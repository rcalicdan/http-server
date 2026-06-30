<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Interfaces;

use Hibla\Socket\Interfaces\ConnectionInterface;

interface ConnectionManagerInterface
{
    /**
     * Start managing the connection, parsing data, and orchestrating Fibers.
     */
    public function handle(ConnectionInterface $connection): void;

    /**
     * Gracefully shut down the connection (drain active requests).
     */
    public function gracefulShutdown(): void;

    /**
     * Get the number of active requests currently processing.
     */
    public function getActiveRequestsCount(): int;

    /**
     * Check if the connection has been upgraded (e.g., WebSockets) or closed.
     */
    public function isUpgraded(): bool;
}
