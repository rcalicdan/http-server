<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Exceptions\JsonEncodingException;
use Hibla\Stream\Interfaces\ReadableStreamInterface;

/**
 * Concrete implementation of an outgoing HTTP Response DTO.
 */
final class Response extends AbstractMessage
{
    /**
     * @var array<int, string> Map of standard HTTP status codes to reason phrases.
     */
    private const array PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-status',
        208 => 'Already Reported',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Requested range not satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        511 => 'Network Authentication Required',
    ];

    /**
     * @param int $statusCode
     * @param array<string, string|list<string>> $headers
     * @param string|ReadableStreamInterface $body
     * @param string $reasonPhrase
     * @param string $protocolVersion
     */
    public function __construct(
        public int $statusCode = 200,
        array $headers = [],
        string|ReadableStreamInterface $body = '',
        public string $reasonPhrase = '',
        string $protocolVersion = '1.1'
    ) {
        if ($this->reasonPhrase === '') {
            $this->reasonPhrase = self::PHRASES[$this->statusCode] ?? 'Unknown';
        }

        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $body;
        $this->protocolVersion = $protocolVersion;
    }

    /**
     * Helper factory to build a plain text response.
     */
    public static function plaintext(string $text, int $status = 200): self
    {
        return new self($status, ['content-type' => 'text/plain; charset=utf-8'], $text);
    }

    /**
     * Helper factory to build a JSON response.
     */
    public static function json(mixed $data, int $status = 200): self
    {
        $json = json_encode(
            $data,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION
        );

        if (! \is_string($json)) {
            throw new JsonEncodingException('Unable to encode given data as JSON: ' . json_last_error_msg());
        }

        return new self($status, ['content-type' => 'application/json'], $json . "\n");
    }

    /**
     * Helper factory to build an HTML response.
     */
    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['content-type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * Helper factory to build an ergonomic Server-Sent Events (SSE) response.
     *
     * @param callable(SseStream): void $emitter
     */
    public static function sse(callable $emitter): self
    {
        $stream = new SseStream();

        $fiber = new \Fiber(function () use ($emitter, $stream) {
            try {
                $emitter($stream);
            } catch (\Throwable) {
                // Connection dropping unwinds the fiber safely
            } finally {
                $stream->end();
            }
        });

        $stream->setEmitterFiber($fiber);

        Loop::addFiber($fiber);

        return new self(200, [
            'content-type' => 'text/event-stream',
            'cache-control' => 'no-cache',
            'connection' => 'keep-alive',
            'x-accel-buffering' => 'no',
        ], $stream);
    }

    /**
     * Retrieves the HTTP status code of the response (e.g., 200, 404).
     *
     * @return int The status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Retrieves the HTTP reason phrase associated with the status code (e.g., "OK", "Not Found").
     *
     * @return string The reason phrase.
     */
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }
}
