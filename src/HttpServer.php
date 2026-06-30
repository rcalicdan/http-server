<?php

declare(strict_types=1);

namespace Hibla\HttpServer;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Exceptions\InvalidConfigurationException;
use Hibla\HttpServer\Exceptions\InvalidResponseException;
use Hibla\HttpServer\Interfaces\HttpServerInterface;
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Internals\ServerWorkerTask;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;
use Hibla\Parallel\ProcessPool;
use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Interfaces\ServerInterface;
use Hibla\Socket\LimitingServer;
use Hibla\Socket\SocketServer;

use function Hibla\asyncFn;

/**
 * Concrete implementation of the fluent, clustered HTTP Server.
 *
 * This class orchestrates incoming TCP connections, parses them using a
 * protocol state machine, and schedules request handler callbacks inside
 * concurrent Fibers.
 */
final class HttpServer implements HttpServerInterface
{
    private const int SIGINT = 2;

    private const int SIGTERM = 15;

    private string $uri;

    private bool $clusterEnabled = false;

    private int $workerCount = 1;

    private ?int $connectionLimit = null;

    private bool $pauseOnLimit = true;

    private bool $loggingEnabled = true;

    private ?ServerInterface $customSocketServer = null;

    private ?string $workerMemoryLimit = null;

    private ?string $clusterBootstrapFile = null;

    /**
     * @var (callable(string): mixed)|null
     */
    private $clusterBootstrapCallback = null;

    /**
     * @var callable|null
     */
    private $onStartCallback = null;

    private ?float $headerTimeout = null;

    private ?float $keepAliveTimeout = null;

    private ?int $workerRestartLimit = 10;

    /**
     * @var int Limit for request body buffering in bytes (Default: 10MB)
     */
    private int $maxBodySize = 10485760;

    /**
     * @var bool True to expose request body as stream instead of buffered string
     */
    private bool $streamingRequests = false;

    /**
     * @var int Maximum total header block size in bytes (Default: 16384)
     */
    private int $maxHeaderSize = 16384;

    /**
     * @var int Maximum number of header fields allowed (Default: 100)
     */
    private int $maxHeaderCount = 100;

    /**
     * @var float Maximum time allowed in seconds to finish active requests during shutdown (Default: 15.0)
     */
    private float $gracefulShutdownTimeout = 15.0;

    /**
     * @var array<string, mixed> Socket context options
     */
    private array $context = [];

    private function __construct(string|int $address)
    {
        $this->uri = $this->normalizeAddress($address);
    }

    /**
     * Create a new HttpServer instance fluently.
     *
     * @param string|int $address Port (e.g., 8080) or URI (e.g., '127.0.0.1:8000')
     *
     * @return self
     */
    public static function create(string|int $address = '0.0.0.0:8000'): self
    {
        return new self($address);
    }

    /**
     * {@inheritdoc}
     */
    public function withSocketServer(ServerInterface $socketServer): static
    {
        $clone = clone $this;
        $clone->customSocketServer = $socketServer;
        $clone->clusterEnabled = false;

        return $clone;
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): static
    {
        $clone = clone $this;

        /** @var array<string, mixed> $merged */
        $merged = array_merge_recursive($this->context, $context);
        $clone->context = $merged;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withTls(array $tlsOptions): static
    {
        $clone = clone $this;
        $clone->uri = str_replace('tcp://', 'tls://', $this->uri);
        $clone->context['tls'] = $tlsOptions;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withCluster(int $workers): static
    {
        if ($workers < 1) {
            throw new InvalidConfigurationException('Cluster mode requires at least 1 worker.');
        }

        $clone = clone $this;
        $clone->clusterEnabled = true;
        $clone->workerCount = $workers;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withWorkerRestartLimit(?int $restartsPerSecond): static
    {
        $clone = clone $this;
        $clone->workerRestartLimit = $restartsPerSecond;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withoutCluster(): static
    {
        $clone = clone $this;
        $clone->clusterEnabled = false;
        $clone->workerCount = 1;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withoutLogging(): static
    {
        $clone = clone $this;
        $clone->loggingEnabled = false;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withWorkerMemoryLimit(string $limit): static
    {
        $clone = clone $this;
        $clone->workerMemoryLimit = $limit;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withClusterBootstrap(string $file, ?callable $callback = null): static
    {
        $clone = clone $this;
        $clone->clusterBootstrapFile = $file;
        $clone->clusterBootstrapCallback = $callback;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function onStart(callable $callback): static
    {
        $clone = clone $this;
        $clone->onStartCallback = $callback;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withMaxBodySize(int $bytes): static
    {
        $clone = clone $this;
        $clone->maxBodySize = $bytes;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withStreamingRequests(bool $enable = true): static
    {
        $clone = clone $this;
        $clone->streamingRequests = $enable;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withHeaderLimits(int $maxSize, int $maxCount): static
    {
        $clone = clone $this;
        $clone->maxHeaderSize = $maxSize;
        $clone->maxHeaderCount = $maxCount;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withMaxConnections(int $limit, bool $pauseOnLimit = true): static
    {
        $clone = clone $this;
        $clone->connectionLimit = $limit;
        $clone->pauseOnLimit = $pauseOnLimit;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withHeaderTimeout(?float $seconds): static
    {
        $clone = clone $this;
        $clone->headerTimeout = $seconds;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withKeepAliveTimeout(?float $seconds): static
    {
        $clone = clone $this;
        $clone->keepAliveTimeout = $seconds;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withGracefulShutdownTimeout(float $seconds): static
    {
        if ($seconds <= 0) {
            throw new InvalidConfigurationException('Graceful shutdown timeout must be a positive number greater than zero.');
        }

        $clone = clone $this;
        $clone->gracefulShutdownTimeout = $seconds;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function start(callable $requestHandler): void
    {
        if ($this->customSocketServer !== null) {
            $this->runCustomSocket($requestHandler);

            return;
        }

        if (! $this->clusterEnabled) {
            $this->runSingleProcess($requestHandler);
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $this->log('Warning: Clustering (SO_REUSEPORT) is not supported on Windows. Falling back to Single Process Mode.');
            $this->runSingleProcess($requestHandler);
        } else {
            $this->runCluster($this->workerCount, $requestHandler);
        }
    }

    /**
     * @param callable():int $triggerShutdown
     * @param float $timeout
     */
    private function executeGracefulDraining(callable $triggerShutdown, float $timeout): void
    {
        $activeCount = $triggerShutdown();

        if ($activeCount === 0) {
            $this->log('No active connections. Shutting down immediately.');
            Loop::stop();

            return;
        }

        $this->log("Waiting for {$activeCount} active request(s) to finish (Timeout: {$timeout}s)...");

        $timeoutId = Loop::addTimer($timeout, function () {
            $this->log('Graceful shutdown timeout reached. Forcing exit.');
            Loop::stop();
        });

        Loop::addPeriodicTimer(0.1, function ($timerId) use ($timeoutId, $triggerShutdown) {
            if ($triggerShutdown() === 0) {
                Loop::cancelTimer($timerId);
                Loop::cancelTimer($timeoutId);
                $this->log('All requests finished cleanly. Exiting.');
                Loop::stop();
            }
        });
    }

    private function runCustomSocket(callable $requestHandler): void
    {
        if ($this->customSocketServer === null) {
            return;
        }

        $triggerShutdown = self::attachProtocolHandler(
            $this->customSocketServer,
            $requestHandler,
            $this->maxBodySize,
            $this->streamingRequests,
            $this->maxHeaderSize,
            $this->maxHeaderCount
        );

        $this->setupSignalHandlers(function () use ($triggerShutdown) {
            static $shuttingDown = false;
            if ($shuttingDown) {
                return;
            }
            $shuttingDown = true;

            $this->log("\nInitiating graceful shutdown...");
            if ($this->customSocketServer !== null) {
                $this->customSocketServer->close();
            }

            $this->executeGracefulDraining($triggerShutdown, $this->gracefulShutdownTimeout);
        });

        $this->log('HTTP Server running on custom socket instance.');
        Loop::run();
    }

    private function runSingleProcess(callable $requestHandler): void
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

        $triggerShutdown = self::attachProtocolHandler(
            $socket,
            $requestHandler,
            $this->maxBodySize,
            $this->streamingRequests,
            $this->maxHeaderSize,
            $this->maxHeaderCount
        );

        $this->setupSignalHandlers(function () use ($socket, $triggerShutdown) {
            static $shuttingDown = false;
            if ($shuttingDown) {
                return;
            }
            $shuttingDown = true;

            $this->log("\nInitiating graceful shutdown (Single Process Mode)...");
            $socket->close();

            $this->executeGracefulDraining($triggerShutdown, $this->gracefulShutdownTimeout);
        });

        $this->log("HTTP Server listening on {$this->getDisplayUri()} (Single Process Mode)");
        Loop::run();
    }

    /**
     * Run the server in clustered mode across multiple CPU cores.
     */
    private function runCluster(int $workers, callable $requestHandler): void
    {
        /** @var array<string, mixed> $context */
        $context = $this->context;

        if (! isset($context['tcp']) || ! \is_array($context['tcp'])) {
            $context['tcp'] = [];
        }
        $context['tcp']['so_reuseport'] = true;

        $workerTask = new ServerWorkerTask(
            $this->uri,
            $context,
            $requestHandler,
            $this->maxBodySize,
            $this->streamingRequests,
            $this->connectionLimit,
            $this->pauseOnLimit,
            $this->maxHeaderSize,
            $this->maxHeaderCount,
            $this->headerTimeout,
            $this->keepAliveTimeout,
            $this->onStartCallback,
            $this->gracefulShutdownTimeout
        );

        $isShuttingDown = false;

        $onWorkerError = function (\Throwable $e) use (&$isShuttingDown): void {
            /** @phpstan-ignore-next-line */
            if ($isShuttingDown) {
                return;
            }

            $this->log(\sprintf(
                "CRITICAL: Worker Task Failed!\n" .
                    "Exception: %s\n" .
                    "Message: %s\n" .
                    "Stack Trace:\n%s\n",
                \get_class($e),
                $e->getMessage(),
                $e->getTraceAsString()
            ));
        };

        $pool = new ProcessPool(size: $workers)
            ->withoutTimeout()
            ->onMessage(function (WorkerMessage $message) {
                $data = $message->data;
                if (
                    \is_array($data)
                    && ($data['type'] ?? '') === 'log'
                    && \is_string($data['message'] ?? null)
                ) {
                    $this->log("[Worker {$message->pid}] {$data['message']}");
                }
            })
        ;

        if ($this->workerMemoryLimit !== null) {
            $pool = $pool->withMemoryLimit($this->workerMemoryLimit);
        }

        if ($this->clusterBootstrapFile !== null) {
            $pool = $pool->withBootstrap($this->clusterBootstrapFile, $this->clusterBootstrapCallback);
        }

        if ($this->workerRestartLimit !== null) {
            $pool = $pool->withMaxRestartPerSecond($this->workerRestartLimit);
        }

        $pool = $pool->onWorkerRespawn(function ($pool) use ($workerTask, $onWorkerError) {
            $this->log('ALERT: Worker process died or retired! Respawning replacement worker...');

            $pool->run($workerTask)->catch($onWorkerError);

            Loop::nextTick(function () use ($pool) {
                $pids = $pool->getWorkerPids();
                $this->log('Active Worker PIDs: ' . implode(', ', $pids));
            });
        });

        $pool = $pool->boot();
        $pids = $pool->getWorkerPids();

        for ($i = 0; $i < $workers; $i++) {
            $pool->run($workerTask)->catch($onWorkerError);
        }

        $this->setupSignalHandlers(asyncFn(function () use ($pool, &$isShuttingDown) {
            $isShuttingDown = true;

            $this->log("\nGracefully shutting down cluster...");
            $pool->drain();
            Loop::stop();
        }));

        $this->log("HTTP Server listening on {$this->getDisplayUri()} (Cluster Mode: {$workers} Workers)");
        $this->log('Active Worker PIDs: ' . implode(', ', $pids));

        Loop::run();
    }

    private function log(string $message): void
    {
        if ($this->loggingEnabled) {
            echo $message . "\n";
        }
    }

    private function setupSignalHandlers(callable $onShutdown): void
    {
        try {
            $handler = static function (int $signal) use ($onShutdown) {
                $onShutdown();
            };

            Loop::addSignal(self::SIGINT, $handler);
            Loop::addSignal(self::SIGTERM, $handler);
        } catch (\BadMethodCallException $e) {
            // Signal handling falls back gracefully on unsupported platforms
        }
    }

    private function normalizeAddress(string|int $address): string
    {
        if (\is_int($address)) {
            return "tcp://0.0.0.0:{$address}";
        }
        if (! str_contains($address, '://')) {
            return "tcp://{$address}";
        }

        return $address;
    }

    private function getDisplayUri(): string
    {
        $display = str_replace('tcp://', 'http://', $this->uri);

        return str_replace('tls://', 'https://', $display);
    }

    /**
     * Wires the socket events to the protocol handler.
     *
     * @internal This is for internal usage and testing purposes.
     *
     * @return callable():int A callback that triggers graceful shutdown and returns the active connection count.
     */
    public static function attachProtocolHandler(
        ServerInterface $socket,
        callable $requestHandler,
        int $maxBodySize = 10485760,
        bool $streamingRequests = false,
        int $maxHeaderSize = 8192,
        int $maxHeaderCount = 100,
        ?float $headerTimeout = null,
        ?float $keepAliveTimeout = null
    ): callable {
        $activeHandlers = [];

        $socket->on('connection', static function (ConnectionInterface $connection) use ($requestHandler, $maxBodySize, $streamingRequests, $maxHeaderSize, $maxHeaderCount, $headerTimeout, $keepAliveTimeout, &$activeHandlers) {
            $protocolHandler = new Http11ProtocolHandler($connection, function (Request $request, ProtocolHandlerInterface $protocol) use ($requestHandler) {
                $fiber = new \Fiber(function () use ($requestHandler, $request, $protocol) {
                    try {
                        $response = $requestHandler($request, $protocol);

                        if (! $response instanceof Response) {
                            throw new InvalidResponseException('Request handler must return an instance of Response');
                        }

                        $protocol->writeResponse($response);
                    } catch (\Throwable $e) {
                        try {
                            $protocol->writeResponse(Response::plaintext("500 Internal Server Error\n" . $e->getMessage(), 500));
                        } catch (\Throwable) {
                            $protocol->getConnection()->close();
                        }
                    }
                });
                Loop::addFiber($fiber);
            }, $maxBodySize, $streamingRequests, $maxHeaderSize, $maxHeaderCount, $headerTimeout, $keepAliveTimeout);

            $handlerId = spl_object_id($protocolHandler);
            $activeHandlers[$handlerId] = $protocolHandler;

            $connection->on('close', static function () use ($handlerId, &$activeHandlers) {
                unset($activeHandlers[$handlerId]);
            });

            $connection->on('data', static function (string $data) use ($protocolHandler) {
                $protocolHandler->handleData($data);
            });
        });

        return static function () use (&$activeHandlers): int {
            $count = 0;

            foreach ($activeHandlers as $handler) {
                if ($handler->isUpgraded()) {
                    continue;
                }

                $handler->gracefulShutdown();

                $count += $handler->getActiveRequestCount();
            }

            return $count;
        };
    }
}
