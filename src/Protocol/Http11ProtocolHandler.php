<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Protocol;

use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Interfaces\RequestInterface;
use Hibla\HttpServer\Interfaces\ResponseInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\RequestBodyStream;
use Hibla\Socket\Interfaces\ConnectionInterface;

class Http11ProtocolHandler implements ProtocolHandlerInterface
{
    private const int STATE_HEADERS = 0;

    private const int STATE_BODY_LENGTH = 1;

    private const int STATE_CHUNK_SIZE = 2;

    private const int STATE_CHUNK_DATA = 3;

    private const int STATE_UPGRADED = 4;

    private const int MAX_HEADER_SIZE = 8192;

    private string $buffer = '';

    private bool $isChunked = false;

    private int $state = self::STATE_HEADERS;

    private int $expectedBodyLength = 0;

    private int $bytesRead = 0;

    private int $currentChunkSize = 0;

    private ?Request $currentRequest = null;

    private ?RequestBodyStream $bodyStream = null;

    /**
     * @param ConnectionInterface $connection The raw TCP/TLS connection
     * @param callable(RequestInterface, ProtocolHandlerInterface): void $onRequest Callback triggered when a full request is parsed
     * @param int $maxBodySize Limit for request body buffering in bytes (Default: 10MB)
     * @param bool $streamingRequests True to enable streaming request bodies
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly mixed $onRequest,
        private readonly int $maxBodySize = 10485760,
        private readonly bool $streamingRequests = false
    ) {
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function handleData(string $data): void
    {
        if ($this->state === self::STATE_UPGRADED) {
            return;
        }

        $this->buffer .= $data;

        while ($this->buffer !== '') {
            if ($this->state === self::STATE_HEADERS) {
                if (! $this->parseHeadersPhase()) {
                    return;
                }
            }

            if ($this->state === self::STATE_BODY_LENGTH) {
                if (! $this->parseBodyLengthPhase()) {
                    return;
                }
            }

            if ($this->state === self::STATE_CHUNK_SIZE) {
                if (! $this->parseChunkSizePhase()) {
                    return;
                }
            }

            if ($this->state === self::STATE_CHUNK_DATA) {
                if (! $this->parseChunkDataPhase()) {
                    return;
                }
            }
        }
    }

    public function writeResponse(ResponseInterface $response): void
    {
        $body = $response->getBody();
        $isStreamingOut = ! \is_string($body);

        $headerBlock = "HTTP/{$response->getProtocolVersion()} {$response->getStatusCode()} {$response->getReasonPhrase()}\r\n";
        $headers = $response->getHeaders();

        if (! isset($headers['server'])) {
            $headers['server'] = ['Hibla/1.0'];
        }

        $isChunkedResponse = false;

        if ($isStreamingOut && ! isset($headers['content-length'])) {
            $isChunkedResponse = true;
            $headers['transfer-encoding'] = ['chunked'];
        } elseif (! $isStreamingOut && ! isset($headers['content-length']) && $response->getStatusCode() !== 101) {
            $headers['content-length'] = [(string) \strlen((string) $body)];
        }

        foreach ($headers as $name => $values) {
            $displayName = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
            foreach ((array)$values as $value) {
                $headerBlock .= "{$displayName}: {$value}\r\n";
            }
        }
        $headerBlock .= "\r\n";

        $reqConnHeader = $this->currentRequest !== null ? $this->currentRequest->getHeaderLine('connection') : '';
        $shouldClose = strtolower($reqConnHeader) === 'close';

        if (\is_string($body)) {
            $this->connection->write($headerBlock . $body);
            if ($shouldClose) {
                $this->connection->close();
            }
        } else {
            $this->connection->write($headerBlock);

            $body->on('data', function (string $chunk) use ($isChunkedResponse) {
                if ($isChunkedResponse) {
                    $this->connection->write(dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n");
                } else {
                    $this->connection->write($chunk);
                }
            });

            $body->on('end', function () use ($isChunkedResponse, $shouldClose) {
                if ($isChunkedResponse) {
                    $this->connection->write("0\r\n\r\n");
                }
                if ($shouldClose) {
                    $this->connection->close();
                }
            });

            $body->on('error', function (\Throwable $e) {
                $this->connection->close();
            });
        }
    }

    public function detach(): string
    {
        $this->state = self::STATE_UPGRADED;

        return $this->buffer;
    }

    private function parseHeadersPhase(): bool
    {
        $headerEndPos = strpos($this->buffer, "\r\n\r\n");

        if ($headerEndPos === false) {
            if (\strlen($this->buffer) > self::MAX_HEADER_SIZE) {
                $this->sendErrorAndClose(431, 'Request Header Fields Too Large');
            }

            return false;
        }

        $rawHeaders = substr($this->buffer, 0, $headerEndPos);
        $this->buffer = substr($this->buffer, $headerEndPos + 4);

        try {
            $this->parseHeaders($rawHeaders);
        } catch (\Throwable $e) {
            $this->sendErrorAndClose(400, 'Bad Request');

            return false;
        }

        // Defensive check to ensure currentRequest was successfully instantiated
        if ($this->currentRequest === null) {
            return false;
        }

        if ($this->streamingRequests) {
            $this->bodyStream = new RequestBodyStream();

            $this->bodyStream->on('pause',  $this->connection->pause(...));
            $this->bodyStream->on('resume',  $this->connection->resume(...));

            $this->currentRequest->setBody($this->bodyStream);

            if (\is_callable($this->onRequest)) {
                ($this->onRequest)($this->currentRequest, $this);
            }
        } else {
            $this->currentRequest->setBody('');
        }

        if ($this->isChunked) {
            $this->state = self::STATE_CHUNK_SIZE;
        } elseif ($this->expectedBodyLength > 0) {
            $this->state = self::STATE_BODY_LENGTH;
        } else {
            $this->finalizeRequest();
        }

        return true;
    }

    private function parseBodyLengthPhase(): bool
    {
        $remainingNeeded = $this->expectedBodyLength - $this->bytesRead;
        $chunk = substr($this->buffer, 0, $remainingNeeded);
        $chunkLength = \strlen($chunk);

        $this->buffer = substr($this->buffer, $chunkLength);
        $this->bytesRead += $chunkLength;

        $this->pushBodyData($chunk);

        if ($this->bytesRead >= $this->expectedBodyLength) {
            $this->finalizeRequest();
        }

        return $this->buffer !== '';
    }

    private function parseChunkSizePhase(): bool
    {
        $pos = strpos($this->buffer, "\r\n");
        if ($pos === false) {
            return false;
        }

        $hex = trim(explode(';', substr($this->buffer, 0, $pos))[0]);
        $this->currentChunkSize = (int) hexdec($hex);
        $this->buffer = substr($this->buffer, $pos + 2);

        if ($this->currentChunkSize === 0) {
            if (\strlen($this->buffer) >= 2 && substr($this->buffer, 0, 2) === "\r\n") {
                $this->buffer = substr($this->buffer, 2);
            }
            $this->finalizeRequest();
        } else {
            $this->state = self::STATE_CHUNK_DATA;
        }

        return true;
    }

    private function parseChunkDataPhase(): bool
    {
        if (\strlen($this->buffer) >= $this->currentChunkSize + 2) {
            $chunk = substr($this->buffer, 0, $this->currentChunkSize);
            $this->pushBodyData($chunk);

            $this->buffer = substr($this->buffer, $this->currentChunkSize + 2);
            $this->state = self::STATE_CHUNK_SIZE;

            return true;
        }

        return false;
    }

    private function pushBodyData(string $data): void
    {
        if ($this->currentRequest === null) {
            return;
        }

        if ($this->streamingRequests && $this->bodyStream !== null) {
            $this->bodyStream->push($data);
        } else {
            $body = $this->currentRequest->getBody();
            if (! \is_string($body)) {
                return;
            }

            $newBody = $body . $data;
            if (\strlen($newBody) > $this->maxBodySize) {
                $this->sendErrorAndClose(413, 'Payload Too Large');

                return;
            }
            $this->currentRequest->setBody($newBody);
        }
    }

    private function finalizeRequest(): void
    {
        if ($this->currentRequest === null) {
            return;
        }

        if ($this->streamingRequests && $this->bodyStream !== null) {
            $this->bodyStream->end();
        } else {
            if (\is_callable($this->onRequest)) {
                ($this->onRequest)($this->currentRequest, $this);
            }
        }

        $this->state = self::STATE_HEADERS;
        $this->currentRequest = null;
        $this->bodyStream = null;
        $this->isChunked = false;
        $this->expectedBodyLength = 0;
        $this->bytesRead = 0;
    }

    private function parseHeaders(string $rawHeaders): void
    {
        $lines = explode("\r\n", $rawHeaders);
        $parts = explode(' ', array_shift($lines));

        if (\count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid Request Line');
        }

        $headers = [];
        foreach ($lines as $line) {
            $partsL = explode(':', $line, 2);
            if (\count($partsL) === 2) {
                $headers[strtolower(trim($partsL[0]))][] = trim($partsL[1]);
            }
        }

        $this->isChunked = isset($headers['transfer-encoding']) && strtolower($headers['transfer-encoding'][0]) === 'chunked';

        if (isset($headers['content-length'])) {
            $this->expectedBodyLength = (int) $headers['content-length'][0];
            if (! $this->streamingRequests && $this->expectedBodyLength > $this->maxBodySize) {
                $this->sendErrorAndClose(413, 'Payload Too Large');
            }
        }

        $serverParams = ['REMOTE_ADDR' => $this->connection->getRemoteAddress()];
        $this->currentRequest = new Request($parts[0], $parts[1], $headers, '', substr($parts[2], 5), $serverParams);
    }

    private function sendErrorAndClose(int $statusCode, string $reason): void
    {
        $this->connection->write("HTTP/1.1 {$statusCode} {$reason}\r\nConnection: close\r\n\r\n");
        $this->connection->close();
        $this->state = self::STATE_UPGRADED;
    }
}
