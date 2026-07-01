<?php

declare(strict_types=1);

namespace Hibla\HttpServer;

/**
 * Encapsulates multi-process cluster configuration options for the high-level HTTP Server.
 *
 * Provides a fluent builder interface for specifying worker memory limits, respawn limits,
 * and per-worker bootstrap initialization routines.
 */
final class ClusterOptions
{
    public private(set) ?string $workerMemoryLimit = null;

    public private(set) ?int $workerRestartLimit = 10;

    public private(set) ?string $clusterBootstrapFile = null;

    /**
     * @var (callable(string): mixed)|null
     */
    public private(set) mixed $clusterBootstrapCallback = null;

    /**
     * Named constructor for fluent chaining.
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * Set a per-worker memory limit for cluster mode.
     *
     * @param string $limit Memory limit in a format accepted by ini_set().
     *
     * @return static
     */
    public function withWorkerMemoryLimit(string $limit): static
    {
        $clone = clone $this;
        $clone->workerMemoryLimit = $limit;

        return $clone;
    }

    /**
     * Set the maximum number of worker respawns allowed per second in cluster mode.
     * Prevents infinite crash loops if a worker dies repeatedly.
     *
     * @param int|null $restartsPerSecond The max allowed respawns per second.
     *
     * @return static
     */
    public function withWorkerRestartLimit(?int $restartsPerSecond): static
    {
        $clone = clone $this;
        $clone->workerRestartLimit = $restartsPerSecond;

        return $clone;
    }

    /**
     * Specify a bootstrap file and/or callback to configure the clean, isolated child
     * subprocess environment when using multi-core clustering.
     *
     * PHYSICAL LIFECYCLE ORDER (Clustered Mode):
     *  1. Subprocess Spawned ──> 2. withClusterBootstrap() ──> 3. onStart() ──> 4. Socket Binds & Listens
     *
     * This is executed at the very birth of the child subprocess, before the HTTP server
     * class itself is instantiated or invoked.
     *
     * NOTE: Composer's standard autoloader is automatically inherited and loaded by the worker
     * process. You do not need to register or require 'vendor/autoload.php' here.
     *
     * DESIGNATED USE CASES:
     * Use this method for process-level structural configuration and compilation:
     *  - Initializing or compiling Dependency Injection (DI) containers for the subprocess.
     *  - Loading legacy files, global functions, or classes not covered by Composer autoloading.
     *  - Setting process-level INI settings (e.g., error reporting or custom error handlers).
     *  - Preparing environment variables specific to worker subprocesses.
     *
     * @param string $file Absolute path to a PHP file to include in the spawned worker process.
     * @param (callable(string $file): mixed)|null $callback Optional callback executed after file inclusion.
     *
     * @return static
     */
    public function withClusterBootstrap(string $file, ?callable $callback = null): static
    {
        $clone = clone $this;
        $clone->clusterBootstrapFile = $file;
        $clone->clusterBootstrapCallback = $callback;

        return $clone;
    }
}
