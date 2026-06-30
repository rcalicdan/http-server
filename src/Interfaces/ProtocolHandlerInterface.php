<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Interfaces;

use Hibla\HttpServer\Message\Response;
use Hibla\Socket\Interfaces\ConnectionInterface;

interface ProtocolHandlerInterface
{
    /**
     * Feed raw bytes from the TCP socket into the protocol parser.
     */
    public function handleData(string $data): void;

    /**
     * Send a response back to the client.
     *
     * @param Response $response
     * @param callable|null $onComplete Optional callback executed when the response has been fully written/streamed.
     */
    public function writeResponse(Response $response, ?callable $onComplete = null): void;

    /**
     * Get the underlying raw TCP/TLS connection.
     * Required for hijacking the stream during an Upgrade (e.g., WebSockets).
     */
    public function getConnection(): ConnectionInterface;

    /**
     * Stop HTTP parsing and detach from the connection.
     * Returns any unparsed bytes currently in the buffer.
     */
    public function detach(): string;

    /**
     * Signals the protocol handler to gracefully shut down.
     */
    public function gracefulShutdown(): void;

    /**
     * Checks if the protocol handler has detached and upgraded the connection.
     */
    public function isUpgraded(): bool;

    /**
     * Get the number of active requests currently being processed by this handler.
     */
    public function getActiveRequestsCount(): int;
}