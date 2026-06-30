<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpServer\HttpServer;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\Promise\Promise;
use Hibla\Socket\Connector;
use Hibla\Socket\SocketServer;
use Hibla\Stream\ThroughStream;

use function Hibla\await;
use function Hibla\delay;

describe('Server-level Graceful Draining', function () {
    it('gracefully drains in-flight requests on shutdown without cutting off clients', function () {
        $socket = new SocketServer('tcp://127.0.0.1:0');
        $url = str_replace('tcp://', 'http://', $socket->getAddress());

        $responseReceived = false;

        $triggerShutdown = HttpServer::attachProtocolHandler($socket, function () {
            await(delay(0.2));

            return ServerResponse::plaintext('Completed safely!');
        });

        $clientPromise = Http::get($url . '/delay');
        $clientPromise->then(function ($response) use (&$responseReceived) {
            if (trim($response->body()) === 'Completed safely!') {
                $responseReceived = true;
            }
        });

        await(delay(0.05));

        $socket->close();
        $activeConnections = $triggerShutdown();

        expect($activeConnections)->toBe(1);

        await(delay(0.2));

        expect($responseReceived)->toBeTrue();
        expect($triggerShutdown())->toBe(0);
    });

    it('allows streaming responses to complete before closing the connection on shutdown', function () {
        $socket = new SocketServer('tcp://127.0.0.1:0');
        $url = str_replace('tcp://', 'http://', $socket->getAddress());

        $chunksReceived = [];
        $stream = new ThroughStream();

        $triggerShutdown = HttpServer::attachProtocolHandler($socket, function () use ($stream) {
            return new ServerResponse(200, [], $stream);
        });

        $clientPromise = Http::stream($url . '/stream', function ($chunk) use (&$chunksReceived) {
            $chunksReceived[] = $chunk;
        });

        await(delay(0.05));

        $socket->close();
        $activeCount = $triggerShutdown();

        expect($activeCount)->toBe(1);

        $stream->write("chunk1\n");
        await(delay(0.05));
        $stream->write("chunk2\n");
        await(delay(0.05));
        $stream->end();

        await($clientPromise);

        await(delay(0.05));

        expect($chunksReceived)->toBe(["chunk1\n", "chunk2\n"]);
        expect($triggerShutdown())->toBe(0);
    });

    it('immediately drops idle keep-alive connections on shutdown', function () {
        $socket = new SocketServer('tcp://127.0.0.1:0');
        $url = str_replace('tcp://', 'http://', $socket->getAddress());

        $triggerShutdown = HttpServer::attachProtocolHandler($socket, function () {
            return ServerResponse::plaintext('OK');
        });

        $rawClient = new Connector();
        $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

        $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $responsePromise = new Promise(function ($resolve) use ($connection) {
            $buffer = '';
            $connection->on('data', function ($chunk) use (&$buffer, $resolve) {
                $buffer .= $chunk;
                if (str_contains($buffer, 'OK')) {
                    $resolve($buffer);
                }
            });
        });

        await($responsePromise);

        $wasClosed = false;
        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $triggerShutdown();

        await(delay(0.05));

        $activeCount = $triggerShutdown();

        expect($activeCount)->toBe(0);
        expect($wasClosed)->toBeTrue();

        $socket->close();
    });

    it('ignores upgraded connections (e.g., WebSockets) during shutdown draining', function () {
        $socket = new SocketServer('tcp://127.0.0.1:0');
        $url = str_replace('tcp://', 'http://', $socket->getAddress());

        $triggerShutdown = HttpServer::attachProtocolHandler($socket, function (Hibla\HttpServer\Message\Request $request, Hibla\HttpServer\Interfaces\ProtocolHandlerInterface $protocol) {
            if ($request->getHeaderLine('Upgrade') === 'websocket') {
                $protocol->writeResponse(new ServerResponse(101, ['Upgrade' => 'websocket', 'Connection' => 'Upgrade']));
                $protocol->detach();
            }

            return null;
        });

        $rawClient = new Connector();
        $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

        $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\n");

        $upgradePromise = new Promise(function ($resolve) use ($connection) {
            $connection->once('data', function ($chunk) use ($resolve) {
                if (str_contains($chunk, '101 Switching Protocols')) {
                    $resolve(true);
                }
            });
        });

        await($upgradePromise);

        $wasClosed = false;
        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $triggerShutdown();
        await(delay(0.05));

        $activeCount = $triggerShutdown();

        expect($activeCount)->toBe(0);
        expect($wasClosed)->toBeFalse();

        $connection->close();
        $socket->close();
    });
});
