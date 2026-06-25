<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Hibla\Stream\Interfaces\ReadableStreamInterface;

/**
 * Base abstract class for HTTP messages (Requests and Responses).
 */
abstract class AbstractMessage
{
    /**
     * @var array<string, list<string>> Normalized message headers
     */
    public array $headers = [];

    /**
     * @var string|ReadableStreamInterface The message body payload
     */
    public string|ReadableStreamInterface $body = '';

    /**
     * @var string The HTTP protocol version
     */
    public string $protocolVersion = '1.1';

    /**
     * Retrieves the HTTP protocol version.
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * Retrieves all message headers.
     *
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Retrieves a specific header's values as a list of strings.
     *
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /**
     * Retrieves a specific header as a single, comma-separated string.
     */
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * Checks if a specific header exists on the message.
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * Sets or overwrites a specific header.
     *
     * @param string $name
     * @param string|array<array-key, string|numeric> $value
     *
     * @return void
     */
    public function setHeader(string $name, string|array $value): void
    {
        $values = [];
        $iterable = \is_array($value) ? $value : [$value];

        foreach ($iterable as $val) {
            $values[] = (string) $val;
        }

        $this->headers[strtolower($name)] = $values;
    }

    /**
     * Appends values to an existing header.
     *
     * @param string $name
     * @param string|array<array-key, string|numeric> $value
     *
     * @return void
     */
    public function addHeader(string $name, string|array $value): void
    {
        $normalizedName = strtolower($name);
        $values = [];
        $iterable = \is_array($value) ? $value : [$value];

        foreach ($iterable as $val) {
            $values[] = (string) $val;
        }

        $this->headers[$normalizedName] = [
            ...($this->headers[$normalizedName] ?? []),
            ...$values,
        ];
    }

    /**
     * Retrieves the message body payload.
     */
    public function getBody(): string|ReadableStreamInterface
    {
        return $this->body;
    }

    /**
     * Sets or updates the message body payload.
     */
    public function setBody(string|ReadableStreamInterface $body): void
    {
        $this->body = $body;
    }

    /**
     * Helper to normalize headers arrays on instantiation.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @return array<string, list<string>>
     */
    protected function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (array) $value;
        }

        return $normalized;
    }
}
