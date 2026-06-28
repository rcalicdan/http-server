<?php

declare(strict_types=1);

use Hibla\HttpServer\HttpServer;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\SocketServer;

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

    if ($expectClose) {
        $connection->shouldReceive('close')->once();
    } else {
        $connection->shouldReceive('close')->zeroOrMoreTimes();
    }

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

    $connection->shouldReceive('pause')->zeroOrMoreTimes();
    $connection->shouldReceive('resume')->zeroOrMoreTimes();

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
    int $maxHeaderCount = 100
): array {
    $socket = new SocketServer('tcp://127.0.0.1:0');
    
    HttpServer::attachProtocolHandler(
        $socket,
        $requestHandler,
        $maxBodySize,
        $streamingRequests,
        $maxHeaderSize,
        $maxHeaderCount
    );
    
    $url = str_replace('tcp://', 'http://', $socket->getAddress());
    
    return [$socket, $url];
}