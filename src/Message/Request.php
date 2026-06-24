<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Hibla\HttpServer\Interfaces\RequestInterface;
use Hibla\Stream\Interfaces\ReadableStreamInterface;

/**
 * Concrete implementation of an incoming HTTP Request Value Object.
 */
final class Request implements RequestInterface
{
    /**
     * @param string $method
     * @param string $uri
     * @param array<string, list<string>> $headers
     * @param string|ReadableStreamInterface $body
     * @param string $protocolVersion
     * @param array<string, mixed> $serverParams
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $headers = [],
        public string|ReadableStreamInterface $body = '',
        public string $protocolVersion = '1.1',
        public array $serverParams = []
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * {@inheritdoc}
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * {@inheritdoc}
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeader(string $name): array
    {
        $normalized = strtolower($name);

        return $this->headers[$normalized] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * {@inheritdoc}
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBody(): string|ReadableStreamInterface
    {
        return $this->body;
    }

    /**
     * {@inheritdoc}
     */
    public function setBody(string|ReadableStreamInterface $body): void
    {
        $this->body = $body;
    }

    /**
     * {@inheritdoc}
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }
}
