<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Protocol;

use Hibla\HttpServer\Exceptions\MessageParsingException;
use Hibla\HttpServer\Exceptions\PayloadTooLargeException;
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\RequestBodyStream;
use Hibla\HttpServer\Message\Response;
use Hibla\Socket\Interfaces\ConnectionInterface;

class Http11ProtocolHandler implements ProtocolHandlerInterface
{
    private const int STATE_HEADERS = 0;

    private const int STATE_BODY_LENGTH = 1;

    private const int STATE_CHUNK_SIZE = 2;

    private const int STATE_CHUNK_DATA = 3;

    private const int STATE_UPGRADED = 4;

    /**
     * Dedicated state for consuming the chunked trailer section (RFC 9112 section 7.1.2).
     */
    private const int STATE_CHUNK_TRAILER = 5;

    private const int MAX_HEADER_SIZE = 8192;

    private string $buffer = '';

    private bool $isChunked = false;

    private int $state = self::STATE_HEADERS;

    private int $expectedBodyLength = 0;

    private int $bytesRead = 0;

    private int $currentChunkSize = 0;

    private ?Request $currentRequest = null;

    private ?RequestBodyStream $bodyStream = null;

    private bool $willCloseConnection = false;

    private string $activeResponseVersion = '1.1';

    /**
     * @param ConnectionInterface $connection The raw TCP/TLS connection
     * @param callable(Request, ProtocolHandlerInterface): void $onRequest Callback triggered when a full request is parsed
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

    /**
     * @inheritDoc
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * @inheritDoc
     */
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

            if ($this->state === self::STATE_CHUNK_TRAILER) {
                if (! $this->parseChunkTrailerPhase()) {
                    return;
                }
            }
        }
    }

    /**
     * Writes an HTTP response back to the underlying connection.
     *
     * Applies the following RFC 9112 connection persistence rules:
     * - section 9.3: The response status line must mirror the request's protocol version.
     * - section 9.3: HTTP/1.0 connections are non-persistent by default; closed unless keep-alive is requested.
     * - section 9.6: A response carrying Connection: close triggers immediate connection teardown.
     *
     * @param Response $response
     *
     * @return void
     */
    public function writeResponse(Response $response): void
    {
        $body = $response->getBody();
        $isStreamingOut = ! \is_string($body);

        $version = $response->getProtocolVersion() === '1.1' ? $this->activeResponseVersion : $response->getProtocolVersion();
        $headerBlock = "HTTP/{$version} {$response->getStatusCode()} {$response->getReasonPhrase()}\r\n";
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

        $shouldClose = $this->willCloseConnection || strtolower($response->getHeaderLine('connection')) === 'close';

        if ($shouldClose && ! isset($headers['connection'])) {
            $headers['connection'] = ['close'];
        } elseif (! $shouldClose && $version === '1.0' && ! isset($headers['connection'])) {
            $headers['connection'] = ['keep-alive'];
        }

        foreach ($headers as $name => $values) {
            $displayName = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
            foreach ((array) $values as $value) {
                $headerBlock .= "{$displayName}: {$value}\r\n";
            }
        }
        $headerBlock .= "\r\n";

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

            $body->on('error', function () {
                $this->connection->close();
            });
        }
    }

    /**
     * @inheritDoc
     */
    public function detach(): string
    {
        $this->state = self::STATE_UPGRADED;

        return $this->buffer;
    }

    /**
     * Extracts the raw header block from the incoming TCP buffer.
     *
     * Complies with RFC 9112 section 2.2 by stripping any leading empty lines (bare CRLFs)
     * before attempting to locate the request-line. Protects against buffer bloat
     * by enforcing a maximum header size.
     *
     * @return bool True if headers were successfully parsed or buffer advanced, false otherwise.
     */
    private function parseHeadersPhase(): bool
    {
        $this->buffer = ltrim($this->buffer, "\r\n");

        if ($this->buffer === '') {
            return false;
        }

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
        } catch (PayloadTooLargeException $e) {
            $this->sendErrorAndClose(413, 'Payload Too Large');

            return false;
        } catch (\Throwable $e) {
            $this->sendErrorAndClose(400, 'Bad Request');

            return false;
        }

        if ($this->currentRequest === null) {
            return false;
        }

        if ($this->streamingRequests) {
            $this->bodyStream = new RequestBodyStream();

            $this->bodyStream->on('pause', $this->connection->pause(...));
            $this->bodyStream->on('resume', $this->connection->resume(...));

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

    /**
     * Parses the hex size of the next chunk in a chunked transfer encoding payload.
     *
     * Complies with RFC 9112 section 7.1.2 by transitioning the state machine to consume
     * the trailer section once the terminal zero-size chunk is encountered.
     *
     * @return bool True if a chunk size was parsed, false if waiting for more data.
     */
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
            $this->state = self::STATE_CHUNK_TRAILER;

            return $this->parseChunkTrailerPhase();
        }

        $this->state = self::STATE_CHUNK_DATA;

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

    /**
     * Consumes the chunked trailer section (RFC 9112 section 7.1.2).
     *
     * After the terminal "0\r\n" chunk, the wire format consists of zero or more
     * trailer fields followed by an empty line (CRLF). This method skips the
     * trailers entirely (as permitted by the RFC) and advances the buffer past
     * the terminating CRLF boundary before finalizing the request.
     *
     * @return bool True if the trailer section was fully consumed, false otherwise.
     */
    private function parseChunkTrailerPhase(): bool
    {
        if (str_starts_with($this->buffer, "\r\n")) {
            $this->buffer = substr($this->buffer, 2);
            $this->finalizeRequest();

            return $this->buffer !== '';
        }

        $trailerEnd = strpos($this->buffer, "\r\n\r\n");
        if ($trailerEnd === false) {
            return false;
        }

        $this->buffer = substr($this->buffer, $trailerEnd + 4);
        $this->finalizeRequest();

        return $this->buffer !== '';
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

    /**
     * Parses the raw header block into a Request value object.
     *
     * This method strictly applies RFC 9112 structural validations. Violations result
     * in an InvalidArgumentException, which triggers a 400 Bad Request response.
     *
     * Enforced RFC 9112 rules:
     * - section 2.2: Absorbs any stray empty lines between the start of the message and the request-line.
     * - section 2.3: Validates HTTP version format and case sensitivity (e.g., "HTTP/1.1").
     * - section 3.2: Enforces that the Host header is mandatory and singular for HTTP/1.1 requests.
     * - section 5.1: Rejects any whitespace between the field name and the colon.
     * - section 5.2: Rejects Obsolete Line Folding (obs-fold) to prevent request smuggling.
     * - section 6.1: Parses Transfer-Encoding as a list, treating the request as chunked only if "chunked" is the final coding.
     * - section 6.2: Rejects multiple conflicting Content-Length values as a smuggling vector.
     * - section 6.3: Removes Content-Length entirely if Transfer-Encoding is present, preventing conflicting body length calculations.
     *
     * @param string $rawHeaders The raw HTTP header string including the request line.
     *
     * @throws MessageParsingException If any structural validation fails.
     * @throws PayloadTooLargeException If the calculated Content-Length exceeds the configured maximum body size.
     */
    private function parseHeaders(string $rawHeaders): void
    {
        $lines = explode("\r\n", $rawHeaders);
        $requestLine = array_shift($lines);

        while ($requestLine === '' && \count($lines) > 0) {
            $requestLine = array_shift($lines);
        }

        if ($requestLine === '') {
            throw new MessageParsingException('Empty request line');
        }

        $parts = explode(' ', $requestLine);
        if (\count($parts) !== 3) {
            throw new MessageParsingException('Invalid Request Line');
        }

        if (preg_match('/^HTTP\/\d\.\d$/', $parts[2]) !== 1) {
            throw new MessageParsingException('Invalid HTTP version: must match HTTP/DIGIT.DIGIT');
        }

        $headers = [];

        foreach ($lines as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                throw new MessageParsingException('Obsolete line folding (obs-fold) is not permitted in requests');
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }

            $rawFieldName = substr($line, 0, $colonPos);
            $fieldValue = trim(substr($line, $colonPos + 1));

            if ($rawFieldName !== rtrim($rawFieldName)) {
                throw new MessageParsingException("Whitespace before colon is not permitted in field name: \"{$rawFieldName}\"");
            }

            $headers[strtolower($rawFieldName)][] = $fieldValue;
        }

        $protocolVersion = substr($parts[2], 5);

        if ($protocolVersion === '1.1') {
            if (! isset($headers['host'])) {
                throw new MessageParsingException('HTTP/1.1 requests MUST include a Host header field');
            }
            if (\count($headers['host']) > 1) {
                throw new MessageParsingException('HTTP/1.1 requests MUST NOT contain more than one Host header field');
            }
        }

        $this->isChunked = false;

        if (isset($headers['transfer-encoding'])) {
            $codings = [];
            foreach ($headers['transfer-encoding'] as $value) {
                foreach (array_map('trim', explode(',', $value)) as $coding) {
                    if ($coding !== '') {
                        $codings[] = strtolower($coding);
                    }
                }
            }
            $this->isChunked = $codings !== [] && end($codings) === 'chunked';
        }

        $this->expectedBodyLength = 0;

        if (isset($headers['content-length'])) {
            $clRaw = [];
            foreach ($headers['content-length'] as $val) {
                foreach (array_map('trim', explode(',', $val)) as $v) {
                    if ($v !== '') {
                        $clRaw[] = $v;
                    }
                }
            }

            $uniqueCl = array_unique($clRaw);

            if (\count($uniqueCl) > 1) {
                throw new MessageParsingException('Multiple conflicting Content-Length values');
            }

            $this->expectedBodyLength = (int) reset($uniqueCl);
        }

        if ($this->isChunked) {
            unset($headers['content-length']);
            $this->expectedBodyLength = 0;
        }

        $serverParams = ['REMOTE_ADDR' => $this->connection->getRemoteAddress()];

        $this->currentRequest = new Request($parts[0], $parts[1], $headers, '', $protocolVersion, $serverParams);

        $connHeader = strtolower($this->currentRequest->getHeaderLine('connection'));
        if ($this->currentRequest->getProtocolVersion() === '1.0') {
            $this->willCloseConnection = ($connHeader !== 'keep-alive');
            $this->activeResponseVersion = '1.0';
        } else {
            $this->willCloseConnection = ($connHeader === 'close');
            $this->activeResponseVersion = '1.1';
        }

        if (! $this->streamingRequests && $this->expectedBodyLength > $this->maxBodySize) {
            throw new PayloadTooLargeException('Payload Too Large');
        }
    }

    private function sendErrorAndClose(int $statusCode, string $reason): void
    {
        $this->connection->write("HTTP/1.1 {$statusCode} {$reason}\r\nConnection: close\r\n\r\n");
        $this->connection->close();
        $this->state = self::STATE_UPGRADED;
    }
}
