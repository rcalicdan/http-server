<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Interfaces;

use Hibla\Stream\Interfaces\ReadableStreamInterface;

/**
 * Represents an outgoing HTTP response message.
 *
 * This contract defines methods to inspect and mutate the HTTP status code,
 * headers, and the response body payload (which can be a buffered string or
 * a dynamic streaming resource like an SseStream or file pointer).
 */
interface ResponseInterface
{
    /**
     * Retrieves the HTTP status code of the response (e.g., 200, 404).
     *
     * @return int The status code.
     */
    public function getStatusCode(): int;

    /**
     * Retrieves the HTTP reason phrase associated with the status code (e.g., "OK", "Not Found").
     *
     * @return string The reason phrase.
     */
    public function getReasonPhrase(): string;

    /**
     * Retrieves the HTTP protocol version (e.g., "1.1", "1.0").
     *
     * @return string The HTTP protocol version.
     */
    public function getProtocolVersion(): string;

    /**
     * Retrieves all response headers.
     *
     * @return array<string, list<string>> An associative array where keys are lowercase header names,
     *                                     and values are lists of string values.
     */
    public function getHeaders(): array;

    /**
     * Retrieves a specific header as a single, comma-separated string.
     *
     * @param string $name The case-insensitive header name.
     *
     * @return string A comma-separated string of header values, or an empty string if not present.
     */
    public function getHeaderLine(string $name): string;

    /**
     * Sets or overwrites a specific response header.
     *
     * @param string $name The case-insensitive header name.
     * @param string|list<string> $value The header value or list of values.
     *
     * @return void
     */
    public function setHeader(string $name, string|array $value): void;

    /**
     * Appends values to an existing response header.
     *
     * @param string $name The case-insensitive header name.
     * @param string|list<string> $value The header value or list of values to append.
     *
     * @return void
     */
    public function addHeader(string $name, string|array $value): void;

    /**
     * Retrieves the response body payload.
     *
     * Depending on the content type:
     * - Buffered: Returns the body as a raw string.
     * - Streaming: Returns a ReadableStreamInterface (for file streams, SSE, etc.).
     *
     * @return string|ReadableStreamInterface The response body.
     */
    public function getBody(): string|ReadableStreamInterface;

    /**
     * Sets or updates the response body payload.
     *
     * @param string|ReadableStreamInterface $body The body string or streaming object.
     *
     * @return void
     */
    public function setBody(string|ReadableStreamInterface $body): void;
}
