<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Interfaces;

use Hibla\Socket\Interfaces\ServerInterface;

/**
 * Defines the contract for the high-level HTTP Server.
 *
 * Implementations of this interface manage the lifecycle of an HTTP server,
 * support fluent configuration, and handle execution in single or clustered modes.
 */
interface HttpServerInterface
{
    /**
     * Inject a custom Socket Server instance.
     *
     * @param ServerInterface $socketServer
     *
     * @return static A new instance with the custom socket server configured.
     */
    public function withSocketServer(ServerInterface $socketServer): static;

    /**
     * Configure raw socket context options.
     *
     * @param array<string, mixed> $context
     *
     * @return static A new instance with the socket context configured.
     */
    public function withContext(array $context): static;

    /**
     * Enable TLS/SSL for secure HTTPS connections.
     *
     * @param array<string, mixed> $tlsOptions
     *
     * @return static A new instance with TLS configured.
     */
    public function withTls(array $tlsOptions): static;

    /**
     * Explicitly enable multi-core clustering using SO_REUSEPORT.
     *
     * @param int $workers Number of workers to spawn. Must be explicitly provided.
     *
     * @return static A new instance with clustering configured.
     */
    public function withCluster(int $workers): static;

    /**
     * Disable multi-core clustering and run entirely in the current process.
     *
     * @return static A new instance with clustering disabled.
     */
    public function withoutCluster(): static;

    /**
     * Disable standard output logging.
     *
     * @return static A new instance with logging disabled.
     */
    public function withoutLogging(): static;

    /**
     * Set a per-worker memory limit for cluster mode.
     *
     * @param string $limit Memory limit in a format accepted by ini_set().
     *
     * @return static A new instance with the memory limit configured.
     */
    public function withWorkerMemoryLimit(string $limit): static;

    /**
     * Set a bootstrap file and/or callback to be executed in each worker process.
     *
     * @param string $file Absolute path to a PHP file to require.
     * @param (callable(string $file): mixed)|null $callback Optional callback to run after file inclusion.
     *
     * @return static A new instance with the bootstrap logic configured.
     */
    public function withBootstrap(string $file, ?callable $callback = null): static;

    /**
     * Configure the maximum allowed request body size for buffered requests.
     *
     * Requests exceeding this size will be rejected with a 413 Payload Too Large.
     *
     * @param int $bytes Max size in bytes.
     *
     * @return static A new instance with the limit configured.
     */
    public function withMaxBodySize(int $bytes): static;

    /**
     * Configure the server to deliver streaming request bodies instead of buffering them.
     *
     * When enabled, $request->getBody() returns a ReadableStreamInterface.
     *
     * @param bool $enable True to enable request streaming, false to buffer.
     *
     * @return static A new instance with streaming configured.
     */
    public function withStreamingRequests(bool $enable = true): static;

    /**
     * Set the maximum number of concurrent connections allowed per worker process.
     *
     * @param int $limit Maximum number of connections.
     * @param bool $pauseOnLimit If true, the server stops accepting new connections
     *                           (backpressure) when full. If false, it accepts and drops them.
     *
     * @return static A new instance with the connection limit configured.
     */
    public function withMaxConnections(int $limit, bool $pauseOnLimit = true): static;

    /**
     * Configure the maximum allowed size and count for HTTP headers.
     *
     * @param int $maxSize Maximum total header block size in bytes (Default: 8192).
     * @param int $maxCount Maximum number of header fields allowed (Default: 100).
     *
     * @return static A new instance with header limits configured.
     */
    public function withHeaderLimits(int $maxSize, int $maxCount): static;

    /**
     * Set the maximum number of worker respawns allowed per second in cluster mode.
     * Prevents infinite crash loops if a worker dies repeatedly.
     *
     * @param int $restartsPerSecond The max allowed respawns per second.
     *
     * @return static A new instance with the restart limit configured.
     */
    public function withWorkerRestartLimit(int $restartsPerSecond): static;

    /**
     * Set the maximum time allowed to receive the complete HTTP request headers.
     * Prevents Slowloris attacks. Disabled by default.
     *
     * @param float|null $seconds Timeout in seconds, or null to disable.
     *
     * @return static
     */
    public function withHeaderTimeout(?float $seconds): static;

    /**
     * Set the maximum idle time allowed for a persistent connection (keep-alive)
     * to wait for the next request. Disabled by default.
     *
     * @param float|null $seconds Timeout in seconds, or null to disable.
     *
     * @return static
     */
    public function withKeepAliveTimeout(?float $seconds): static;

    /**
     * Start the HTTP Server and block the current thread to process incoming requests.
     *
     * @param callable $requestHandler Callback invoked for each incoming request.
     *
     * @return void
     */
    public function start(callable $requestHandler): void;
}
