<?php

declare(strict_types=1);

use Hibla\HttpServer\ClusterOptions;
use Hibla\HttpServer\Exceptions\InvalidConfigurationException;
use Hibla\HttpServer\HttpServer;
use Hibla\Socket\Interfaces\ServerInterface;

describe('HttpServer Configuration & Instantiation', function () {

    it('creates an instance with default address', function () {
        $server = HttpServer::create();

        expect(getServerProperty($server, 'uri'))->toBe('tcp://0.0.0.0:8000')
            ->and($server)->toBeInstanceOf(HttpServer::class)
            ->and(getServerProperty($server, 'clusterEnabled'))->toBeFalse()
            ->and(getServerProperty($server, 'loggingEnabled'))->toBeTrue()
        ;
    });

    it('normalizes integer ports to a tcp uri', function () {
        $server = HttpServer::create(8080);
        expect(getServerProperty($server, 'uri'))->toBe('tcp://0.0.0.0:8080');
    });

    it('preserves an explicit uri scheme', function () {
        $server = HttpServer::create('unix:///var/run/app.sock');
        expect(getServerProperty($server, 'uri'))->toBe('unix:///var/run/app.sock');
    });

    it('is strictly immutable when applying configuration', function () {
        $original = HttpServer::create(8080);
        $configured = $original->withoutLogging();

        expect($original)->not->toBe($configured)
            ->and(getServerProperty($original, 'loggingEnabled'))->toBeTrue()
            ->and(getServerProperty($configured, 'loggingEnabled'))->toBeFalse()
        ;
    });

    it('configures raw socket context options', function () {
        $server = HttpServer::create()->withContext(['tcp' => ['backlog' => 511]]);
        $context = getServerProperty($server, 'context');
        expect($context)->toHaveKey('tcp')
            ->and($context['tcp'])->toHaveKey('backlog', 511)
        ;
    });

    it('configures tls and rewrites the uri scheme', function () {
        $server = HttpServer::create('127.0.0.1:443')->withTls(['local_cert' => '/path/to/cert.pem']);
        $context = getServerProperty($server, 'context');
        expect(getServerProperty($server, 'uri'))->toBe('tls://127.0.0.1:443')
            ->and($context)->toHaveKey('tls')
            ->and($context['tls'])->toHaveKey('local_cert', '/path/to/cert.pem')
        ;
    });

    it('configures cluster mode correctly', function () {
        $server = HttpServer::create()->withCluster(4);

        expect(getServerProperty($server, 'clusterEnabled'))->toBeTrue()
            ->and(getServerProperty($server, 'workerCount'))->toBe(4)
        ;
    });

    it('configures cluster mode correctly with multiple workers', function () {
        $server = HttpServer::create()->withCluster(4);

        expect(getServerProperty($server, 'clusterEnabled'))->toBeTrue()
            ->and(getServerProperty($server, 'workerCount'))->toBe(4)
        ;
    });

    it('allows cluster mode with exactly 1 worker for debugging/isolation', function () {
        $server = HttpServer::create()->withCluster(1);

        expect(getServerProperty($server, 'clusterEnabled'))->toBeTrue()
            ->and(getServerProperty($server, 'workerCount'))->toBe(1)
        ;
    });

    it('throws an exception if cluster mode is requested with 0 workers', function () {
        HttpServer::create()->withCluster(0);
    })->throws(InvalidConfigurationException::class, 'Cluster mode requires at least 1 worker.');

    it('can disable cluster mode explicitly', function () {
        $server = HttpServer::create()->withCluster(4)->withoutCluster();

        expect(getServerProperty($server, 'clusterEnabled'))->toBeFalse()
            ->and(getServerProperty($server, 'workerCount'))->toBe(1)
            ->and(getServerProperty($server, 'clusterOptions'))->toBeNull()
        ;
    });

    it('can configure worker memory limits in cluster options', function () {
        $options = ClusterOptions::make()->withWorkerMemoryLimit('256M');
        $server = HttpServer::create()->withCluster(2, $options);

        expect(getServerProperty($server, 'clusterOptions')->workerMemoryLimit)->toBe('256M');
    });

    it('can configure cluster bootstrap files and callbacks in cluster options', function () {
        $callback = fn () => true;
        $options = ClusterOptions::make()->withClusterBootstrap('/app/bootstrap.php', $callback);
        $server = HttpServer::create()->withCluster(2, $options);

        expect(getServerProperty($server, 'clusterOptions')->clusterBootstrapFile)->toBe('/app/bootstrap.php')
            ->and(getServerProperty($server, 'clusterOptions')->clusterBootstrapCallback)->toBe($callback)
        ;
    });

    it('can configure a unified application start callback', function () {
        $callback = fn () => true;
        $server = HttpServer::create()->onStart($callback);

        expect(getServerProperty($server, 'onStartCallback'))->toBe($callback);
    });

    it('can configure header timeout and keep alive timeout', function () {
        $server = HttpServer::create()
            ->withHeaderTimeout(0.5)
            ->withKeepAliveTimeout(1.5)
        ;

        expect(getServerProperty($server, 'headerTimeout'))->toBe(0.5)
            ->and(getServerProperty($server, 'keepAliveTimeout'))->toBe(1.5)
        ;
    });

    it('can disable header timeout and keep alive timeout by passing null', function () {
        $server = HttpServer::create()
            ->withHeaderTimeout(null)
            ->withKeepAliveTimeout(null)
        ;

        expect(getServerProperty($server, 'headerTimeout'))->toBeNull()
            ->and(getServerProperty($server, 'keepAliveTimeout'))->toBeNull()
        ;
    });

    it('can configure maximum body size', function () {
        $server = HttpServer::create()->withMaxBodySize(5000000);

        expect(getServerProperty($server, 'maxBodySize'))->toBe(5000000);
    });

    it('can toggle streaming requests', function () {
        $server = HttpServer::create()->withStreamingRequests(true);
        expect(getServerProperty($server, 'streamingRequests'))->toBeTrue();

        $server2 = $server->withStreamingRequests(false);
        expect(getServerProperty($server2, 'streamingRequests'))->toBeFalse();
    });

    it('can configure header limits for size and count', function () {
        $server = HttpServer::create()->withHeaderLimits(16384, 150);

        expect(getServerProperty($server, 'maxHeaderSize'))->toBe(16384)
            ->and(getServerProperty($server, 'maxHeaderCount'))->toBe(150)
        ;
    });

    it('can inject a custom socket server', function () {
        $mockSocket = Mockery::mock(ServerInterface::class);
        $server = HttpServer::create()->withSocketServer($mockSocket);

        expect(getServerProperty($server, 'customSocketServer'))->toBe($mockSocket)
            ->and(getServerProperty($server, 'clusterEnabled'))->toBeFalse()
        ;
    });

    it('can configure maximum concurrent connections and pause mode', function () {
        $server = HttpServer::create()->withMaxConnections(150, false);

        expect(getServerProperty($server, 'connectionLimit'))->toBe(150)
            ->and(getServerProperty($server, 'pauseOnLimit'))->toBeFalse()
        ;
    });

    it('has a default worker restart limit of 10 inside ClusterOptions', function () {
        $options = ClusterOptions::make();
        expect($options->workerRestartLimit)->toBe(10);
    });

    it('can disable the worker restart limit explicitly by passing null inside ClusterOptions', function () {
        $options = ClusterOptions::make()->withWorkerRestartLimit(null);
        expect($options->workerRestartLimit)->toBeNull();
    });

    it('has a default maximum concurrent requests per connection of 128', function () {
        $server = HttpServer::create();

        expect(getServerProperty($server, 'maxConcurrentRequestsPerConnection'))->toBe(128);
    });

    it('can configure the maximum concurrent requests per connection', function () {
        $server = HttpServer::create()->withMaxConcurrentRequestsPerConnection(256);

        expect(getServerProperty($server, 'maxConcurrentRequestsPerConnection'))->toBe(256);
    });

    it('throws an exception if maximum concurrent requests per connection is less than 1', function () {
        HttpServer::create()->withMaxConcurrentRequestsPerConnection(0);
    })->throws(InvalidConfigurationException::class, 'Concurrent requests limit must be at least 1.');
});
