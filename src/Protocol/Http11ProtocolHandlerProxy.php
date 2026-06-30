<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Protocol;

use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Response;
use Hibla\Socket\Interfaces\ConnectionInterface;

/**
 * @internal
 *
 * A lightweight proxy that binds a specific pipelined request sequence ID to the
 * HTTP/1.1 protocol handler. This ensures that when concurrent fibers generate
 * responses out of order, they are queued and written to the wire strictly in the
 * order the requests were received (FIFO) to comply with RFC 9112 Section 9.3.
 */
final class Http11ProtocolHandlerProxy implements ProtocolHandlerInterface
{
    public function __construct(
        private readonly Http11ProtocolHandler $handler,
        private readonly int $sequenceId,
        private readonly string $requestMethod
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function handleData(string $data): void
    {
        $this->handler->handleData($data);
    }

    /**
     * {@inheritDoc}
     */
    public function writeResponse(Response $response): void
    {
        $this->handler->writeResponseWithSequence($this->sequenceId, $this->requestMethod, $response);
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->handler->getConnection();
    }

    /**
     * {@inheritDoc}
     */
    public function detach(): string
    {
        return $this->handler->detach();
    }

    /**
     * {@inheritDoc}
     */
    public function gracefulShutdown(): void
    {
        $this->handler->gracefulShutdown();
    }

    /**
     * {@inheritDoc}
     */
    public function isUpgraded(): bool
    {
        return $this->handler->isUpgraded();
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveRequestCount(): int
    {
        return $this->handler->getActiveRequestCount();
    }
}
