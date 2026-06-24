<?php

declare(strict_types=1);

namespace Hibla\HttpServer;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Interfaces\HttpServerInterface;
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Interfaces\RequestInterface;
use Hibla\HttpServer\Interfaces\ResponseInterface;
use Hibla\HttpServer\Internals\ServerWorkerTask;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;
use Hibla\Parallel\Parallel;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Interfaces\ServerInterface;
use Hibla\Socket\SocketServer;

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

    private bool $loggingEnabled = true;

    private ?ServerInterface $customSocketServer = null;

    private ?string $workerMemoryLimit = null;

    private ?string $bootstrapFile = null;

    /**
     * @var (callable(string): mixed)|null
     */
    private $bootstrapCallback = null;

    /**
     * @var int Limit for request body buffering in bytes (Default: 10MB)
     */
    private int $maxBodySize = 10485760;

    /**
     * @var bool True to expose request body as stream instead of buffered string
     */
    private bool $streamingRequests = false;

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
        if ($workers <= 1) {
            throw new \InvalidArgumentException('Cluster mode requires at least 2 workers.');
        }

        $clone = clone $this;
        $clone->clusterEnabled = true;
        $clone->workerCount = $workers;

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
    public function withBootstrap(string $file, ?callable $callback = null): static
    {
        $clone = clone $this;
        $clone->bootstrapFile = $file;
        $clone->bootstrapCallback = $callback;

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

    private function runCustomSocket(callable $requestHandler): void
    {
        if ($this->customSocketServer === null) {
            return;
        }

        self::attachProtocolHandler($this->customSocketServer, $requestHandler, $this->maxBodySize, $this->streamingRequests);

        $this->setupSignalHandlers(function () {
            if ($this->customSocketServer !== null) {
                $this->customSocketServer->close();
            }
            Loop::stop();
        });

        $this->log('HTTP Server running on custom socket instance.');
        Loop::run();
    }

    private function runSingleProcess(callable $requestHandler): void
    {
        $socket = new SocketServer($this->uri, $this->context);
        self::attachProtocolHandler($socket, $requestHandler, $this->maxBodySize, $this->streamingRequests);

        $this->setupSignalHandlers(function () use ($socket) {
            $this->log("\nStopping HTTP Server (Single Process Mode)...");
            $socket->close();
            Loop::stop();
        });

        $this->log("HTTP Server listening on {$this->uri} (Single Process Mode)");
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
            $this->streamingRequests
        );

        $onWorkerError = function (\Throwable $e): void {
            $this->log(sprintf(
                "CRITICAL: Worker Task Failed!\n" .
                "Exception: %s\n" .
                "Message: %s\n" .
                "Stack Trace:\n%s\n",
                get_class($e),
                $e->getMessage(),
                $e->getTraceAsString()
            ));
        };

        $pool = Parallel::pool(size: $workers)->withoutTimeout();

        if ($this->workerMemoryLimit !== null) {
            $pool = $pool->withMemoryLimit($this->workerMemoryLimit);
        }

        if ($this->bootstrapFile !== null) {
            $pool = $pool->withBootstrap($this->bootstrapFile, $this->bootstrapCallback);
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

        $this->setupSignalHandlers(function () use ($pool) {
            $this->log("\nGracefully shutting down cluster...");
            $pool->drain();
            Loop::stop();
        });

        $this->log("HTTP Server listening on {$this->uri} (Cluster Mode: {$workers} Workers)");
        $this->log('Active Worker PIDs: ' . implode(', ', $pids));

        Loop::run();
    }

    private function log(string $message): void
    {
        if ($this->loggingEnabled) {
            echo $message . "\n";
        }
    }

    private function setupSignalHandlers(\Closure $onShutdown): void
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

    /**
     * @internal Wires the socket events to the protocol handler.
     */
    public static function attachProtocolHandler(
        ServerInterface $socket,
        callable $requestHandler,
        int $maxBodySize = 10485760,
        bool $streamingRequests = false
    ): void {
        $socket->on('connection', static function (ConnectionInterface $connection) use ($requestHandler, $maxBodySize, $streamingRequests) {
            $protocolHandler = new Http11ProtocolHandler($connection, function (RequestInterface $request, ProtocolHandlerInterface $protocol) use ($requestHandler) {
                $fiber = new \Fiber(function () use ($requestHandler, $request, $protocol) {
                    try {
                        $response = $requestHandler($request);
                        if (! $response instanceof ResponseInterface) {
                            throw new \LogicException('Request handler must return an instance of ResponseInterface');
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
            }, $maxBodySize, $streamingRequests);

            $connection->on('data', static function (string $data) use ($protocolHandler) {
                $protocolHandler->handleData($data);
            });
        });
    }
}
