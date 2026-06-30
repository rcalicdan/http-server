<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Internals;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\HttpServer;
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\Socket\LimitingServer;
use Hibla\Socket\SocketServer;

use function Hibla\emit;

/**
 * @internal
 *
 * Serializable, invokable task executed by persistent cluster worker processes.
 */
final class ServerWorkerTask
{
    private const int SIGINT = 2;

    private const int SIGTERM = 15;

    /**
     * @param string $uri
     * @param array<string, mixed> $context
     * @param callable(Request, ProtocolHandlerInterface): (Response|null) $requestHandler Callback invoked for each incoming request.
     * @param int $maxBodySize Limit for request body buffering in bytes (Default: 10MB)
     * @param bool $streamingRequests True to enable streaming request bodies
     * @param int|null $connectionLimit
     * @param bool $pauseOnLimit
     * @param int $maxHeaderSize Maximum total size of the header block in bytes
     * @param int $maxHeaderCount Maximum number of header fields allowed per request
     * @param callable|null $onStartCallback
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
        private readonly ?float $keepAliveTimeout = null,
        private readonly mixed $onStartCallback = null,
        private readonly float $gracefulShutdownTimeout = 15.0,
        private readonly int $maxConcurrentRequestsPerConnection = 128
    ) {
    }

    public function __invoke(): void
    {
        if ($this->onStartCallback !== null) {
            ($this->onStartCallback)();
        }

        $socket = new SocketServer($this->uri, $this->context);

        if ($this->connectionLimit !== null) {
            $socket = new LimitingServer(
                $socket,
                $this->connectionLimit,
                $this->pauseOnLimit
            );
        }

        $triggerShutdown = HttpServer::attachProtocolHandler(
            $socket,
            $this->requestHandler,
            $this->maxBodySize,
            $this->streamingRequests,
            $this->maxHeaderSize,
            $this->maxHeaderCount,
            $this->headerTimeout,
            $this->keepAliveTimeout,
            $this->maxConcurrentRequestsPerConnection
        );

        $gracefulShutdownTimeout = $this->gracefulShutdownTimeout;

        try {
            $handler = static function (int $signal) use ($socket, $triggerShutdown, $gracefulShutdownTimeout) {
                static $shuttingDown = false;
                if ($shuttingDown) {
                    return;
                }
                $shuttingDown = true;

                $socket->close();

                $activeCount = $triggerShutdown();
                if ($activeCount > 0) {
                    emit([
                        'type' => 'log',
                        'message' => "Worker is draining {$activeCount} active connection(s) before exit.",
                    ]);
                }

                if ($activeCount === 0) {
                    Loop::stop();

                    return;
                }

                $timeoutId = Loop::addTimer($gracefulShutdownTimeout, static function () {
                    Loop::stop();
                });

                Loop::addPeriodicTimer(0.1, static function ($timerId) use ($timeoutId, $triggerShutdown) {
                    if ($triggerShutdown() === 0) {
                        Loop::cancelTimer($timerId);
                        Loop::cancelTimer($timeoutId);
                        Loop::stop();
                    }
                });
            };

            Loop::addSignal(self::SIGINT, $handler);
            Loop::addSignal(self::SIGTERM, $handler);
        } catch (\BadMethodCallException $e) {
            // Signal handling falls back gracefully on unsupported platforms
        }

        // When this returns, the worker's Event Loop automatically takes over.
    }
}
