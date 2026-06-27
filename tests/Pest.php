<?php

declare(strict_types=1);

use Hibla\HttpServer\HttpServer;
use Hibla\Socket\Interfaces\ConnectionInterface;

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
