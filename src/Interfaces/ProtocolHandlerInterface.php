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
     */
    public function writeResponse(Response $response): void;

    /**
     * Get the underlying raw TCP/TLS connection.
     * Required for hijacking the stream during an Upgrade (e.g., WebSockets).
     */
    public function getConnection(): ConnectionInterface;

    /**
     * Stop HTTP parsing and detach from the connection.
     * Returns any unparsed bytes currently in the buffer (which may be the first
     * frames of the newly upgraded protocol, like WebSocket frames).
     */
    public function detach(): string;
}