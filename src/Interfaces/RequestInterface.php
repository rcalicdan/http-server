<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Interfaces;

use Hibla\Stream\Interfaces\ReadableStreamInterface;

/**
 * Represents an incoming HTTP request message.
 *
 * This contract defines methods to inspect the HTTP request line, headers,
 * server metadata, and the message body (which can be fully buffered or streaming).
 */
interface RequestInterface
{
    /**
     * Retrieves the HTTP method of the request (e.g., "GET", "POST").
     *
     * @return string The HTTP method string.
     */
    public function getMethod(): string;

    /**
     * Retrieves the request URI or path (e.g., "/api/v1/users?page=2").
     *
     * @return string The raw request URI string.
     */
    public function getUri(): string;

    /**
     * Retrieves the HTTP protocol version (e.g., "1.1", "1.0").
     *
     * @return string The HTTP protocol version.
     */
    public function getProtocolVersion(): string;

    /**
     * Retrieves all request headers.
     *
     * @return array<string, list<string>> An associative array where keys are lowercase header names,
     *                                     and values are a list of string values for that header.
     */
    public function getHeaders(): array;

    /**
     * Retrieves a specific header's values as a list of strings.
     *
     * @param string $name The case-insensitive header name.
     *
     * @return list<string> An array of string values for the header, or an empty array if not present.
     */
    public function getHeader(string $name): array;

    /**
     * Retrieves a specific header as a single, comma-separated string.
     *
     * @param string $name The case-insensitive header name.
     *
     * @return string A comma-separated string of header values, or an empty string if not present.
     */
    public function getHeaderLine(string $name): string;

    /**
     * Checks if a specific header exists on the request.
     *
     * @param string $name The case-insensitive header name.
     *
     * @return bool True if the header exists, false otherwise.
     */
    public function hasHeader(string $name): bool;

    /**
     * Retrieves the request body payload.
     *
     * Depending on whether streaming requests are enabled:
     * - Disabled (Default): Returns the fully buffered body as a raw string.
     * - Enabled: Returns a ReadableStreamInterface emitting incoming data chunks.
     *
     * @return string|ReadableStreamInterface The request body.
     */
    public function getBody(): string|ReadableStreamInterface;

    /**
     * Updates or sets the request body payload.
     *
     * @param string|ReadableStreamInterface $body The new body string or streaming object.
     *
     * @return void
     */
    public function setBody(string|ReadableStreamInterface $body): void;

    /**
     * Retrieves server-side environment parameters.
     *
     * Typically includes connection metadata like 'REMOTE_ADDR' (the client's IP).
     *
     * @return array<string, mixed>
     */
    public function getServerParams(): array;
}
