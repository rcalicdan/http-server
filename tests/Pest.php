<?php

declare(strict_types=1);

use Hibla\HttpServer\HttpServer;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\SocketServer;

function debug(string $message): void
{
    fwrite(STDERR, "[DEBUG] {$message}\n");
}

function getServerProperty(HttpServer $server, string $property): mixed
{
    $reflection = new ReflectionClass($server);

    return $reflection->getProperty($property)->getValue($server);
}

function mockConnection(string &$buffer, bool $expectClose = false): ConnectionInterface
{
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');
    $connection->shouldReceive('write')->andReturnUsing(function (string $data) use (&$buffer) {
        $buffer .= $data;

        return true;
    });

    $connection->shouldReceive('end')->andReturnUsing(function (?string $data = null) use (&$buffer) {
        if ($data !== null) {
            $buffer .= $data;
        }
    });

    $closeListeners = [];

    $connection->shouldReceive('on')->zeroOrMoreTimes()->andReturnUsing(function (string $event, callable $listener) use (&$closeListeners) {
        if ($event === 'close') {
            $closeListeners[] = $listener;
        }
    });

    $connection->shouldReceive('close')->zeroOrMoreTimes()->andReturnUsing(function () use (&$closeListeners) {
        foreach ($closeListeners as $listener) {
            $listener();
        }
    });

    return $connection;
}

function mockStreamingConnection(string &$buffer): ConnectionInterface
{
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

    $connection->shouldReceive('write')->andReturnUsing(function (string $data) use (&$buffer) {
        $buffer .= $data;

        return true;
    });

    $connection->shouldReceive('end')->andReturnUsing(function (?string $data = null) use (&$buffer) {
        if ($data !== null) {
            $buffer .= $data;
        }
    });

    $connection->shouldReceive('pause')->zeroOrMoreTimes();
    $connection->shouldReceive('resume')->zeroOrMoreTimes();

    $closeListeners = [];

    $connection->shouldReceive('on')->zeroOrMoreTimes()->andReturnUsing(function (string $event, callable $listener) use (&$closeListeners) {
        if ($event === 'close') {
            $closeListeners[] = $listener;
        }
    });

    $connection->shouldReceive('close')->zeroOrMoreTimes()->andReturnUsing(function () use (&$closeListeners) {
        foreach ($closeListeners as $listener) {
            $listener();
        }
    });

    return $connection;
}

/**
 * @return array{0: SocketServer, 1: string}
 */
function createTestServer(
    callable $requestHandler,
    int $maxBodySize = 10485760,
    bool $streamingRequests = false,
    int $maxHeaderSize = 8192,
    int $maxHeaderCount = 100,
    array $context = [],
    ?float $headerTimeout = null,
    ?float $keepAliveTimeout = null
): array {
    $scheme = isset($context['tls']) ? 'tls://' : 'tcp://';
    $socket = new SocketServer($scheme . '127.0.0.1:0', $context);

    HttpServer::attachProtocolHandler(
        $socket,
        $requestHandler,
        $maxBodySize,
        $streamingRequests,
        $maxHeaderSize,
        $maxHeaderCount,
        $headerTimeout,
        $keepAliveTimeout
    );

    $url = str_replace(['tcp://', 'tls://'], ['http://', 'https://'], $socket->getAddress());

    return [$socket, $url];
}
