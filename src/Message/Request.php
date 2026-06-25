<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Hibla\Stream\Interfaces\ReadableStreamInterface;

/**
 * Concrete implementation of an incoming HTTP Request Value Object.
 */
final class Request extends AbstractMessage
{
    /**
     * @param string $method
     * @param string $uri
     * @param array<string, string|list<string>> $headers
     * @param string|ReadableStreamInterface $body
     * @param string $protocolVersion
     * @param array<string, mixed> $serverParams
     */
    public function __construct(
        public string $method,
        public string $uri,
        array $headers = [],
        string|ReadableStreamInterface $body = '',
        string $protocolVersion = '1.1',
        public array $serverParams = []
    ) {
        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $body;
        $this->protocolVersion = $protocolVersion;
    }

    /**
     * Retrieves the HTTP method of the request (e.g., "GET", "POST").
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Retrieves the request URI or path.
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Retrieves server-side environment parameters.
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }
}
