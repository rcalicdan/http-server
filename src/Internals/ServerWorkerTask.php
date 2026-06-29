<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Internals;

use Hibla\HttpServer\HttpServer;
use Hibla\Socket\LimitingServer;
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
     * @param int|null $connectionLimit
     * @param bool $pauseOnLimit
     * @param int $maxHeaderSize Maximum total size of the header block in bytes
     * @param int $maxHeaderCount Maximum number of header fields allowed per request
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $context,
        private readonly mixed $requestHandler,
        private readonly int $maxBodySize = 10485760,
        private readonly bool $streamingRequests = false,
        private readonly ?int $connectionLimit = null,
        private readonly bool $pauseOnLimit = true,
        private readonly int $maxHeaderSize = 8192,
        private readonly int $maxHeaderCount = 100,
        private readonly ?float $headerTimeout = null,
        private readonly ?float $keepAliveTimeout = null
    ) {
    }

    public function __invoke(): void
    {
        $socket = new SocketServer($this->uri, $this->context);

        if ($this->connectionLimit !== null) {
            $socket = new LimitingServer(
                $socket,
                $this->connectionLimit,
                $this->pauseOnLimit
            );
        }

        HttpServer::attachProtocolHandler(
            $socket,
            $this->requestHandler,
            $this->maxBodySize,
            $this->streamingRequests,
            $this->maxHeaderSize,
            $this->maxHeaderCount,
            $this->headerTimeout,
            $this->keepAliveTimeout
        );

        // When this returns, the worker's Event Loop automatically takes over.
    }
}
