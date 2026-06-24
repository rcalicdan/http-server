<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Internals;

use Hibla\HttpServer\HttpServer;
use Hibla\Socket\SocketServer;

/**
 * @internal
 *
 * Serializable, invokable task executed by persistent cluster worker processes.
 */
final class ServerWorkerTask
{
    /**
     * @param string $uri
     * @param array<string, mixed> $context
     * @param callable $requestHandler
     * @param int $maxBodySize Limit for request body buffering in bytes (Default: 10MB)
     * @param bool $streamingRequests True to enable streaming request bodies
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $context,
        private readonly mixed $requestHandler,
        private readonly int $maxBodySize = 10485760,
        private readonly bool $streamingRequests = false
    ) {
    }

    public function __invoke(): void
    {
        $socket = new SocketServer($this->uri, $this->context);

        HttpServer::attachProtocolHandler(
            $socket,
            $this->requestHandler,
            $this->maxBodySize,
            $this->streamingRequests
        );

        // When this returns, the worker's Event Loop automatically takes over.
    }
}
