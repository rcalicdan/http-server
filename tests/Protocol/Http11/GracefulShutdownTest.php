<?php

declare(strict_types=1);

use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;
use Hibla\Stream\ThroughStream;

describe('Protocol-level Graceful Shutdown', function () {
    it('immediately closes the connection on graceful shutdown if idle', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);
        $wasClosed = false;

        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler($connection, function () {
        });

        $handler->gracefulShutdown();

        expect($wasClosed)->toBeTrue();
    });

    it('does not close the connection immediately if a request is actively processing', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);
        $wasClosed = false;

        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler($connection, function (Request $request, ProtocolHandlerInterface $protocol) {
            // Simulate slow-running request handler
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $handler->gracefulShutdown();

        expect($wasClosed)->toBeFalse();

        $handler->writeResponse(Response::plaintext('OK'));

        expect($wasClosed)->toBeTrue();
        expect($buffer)->toContain('Connection: close');
    });

    it('discards pipelined requests after a graceful shutdown has been triggered during the first request', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);

        $requestsProcessed = 0;
        $handler = null;

        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$requestsProcessed, &$handler) {
            $requestsProcessed++;

            if ($requestsProcessed === 1) {
                $handler->gracefulShutdown();
                $handler->writeResponse(Response::plaintext('First OK'));
            }
        });

        $handler->handleData("GET /first HTTP/1.1\r\nHost: localhost\r\n\r\nGET /second HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($requestsProcessed)->toBe(1);
        expect($buffer)->toContain('First OK');
    });

    it('is idempotent when gracefulShutdown is called multiple times consecutively', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);
        $wasClosed = false;

        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler($connection, function () {
        });

        $handler->gracefulShutdown();
        $handler->gracefulShutdown();
        $handler->gracefulShutdown();

        expect($wasClosed)->toBeTrue();
    });

    it('ignores graceful shutdown if the connection has already been detached/upgraded', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);
        $wasClosed = false;

        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler($connection, function () {
        });

        $handler->detach();

        $handler->gracefulShutdown();

        // The socket must not close because the HTTP protocol handler no longer owns it
        expect($wasClosed)->toBeFalse();
    });

    it('overrides an application-provided Connection: keep-alive header to close during shutdown', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);

        $handler = new Http11ProtocolHandler($connection, function (Request $request, ProtocolHandlerInterface $protocol) {
            // Do nothing immediately. Simulate a slow async response.
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $handler->gracefulShutdown();

        $handler->writeResponse(new Response(200, ['Connection' => 'keep-alive'], 'OK'));

        expect($buffer)->toContain('Connection: close')
            ->and($buffer)->not->toContain('Connection: keep-alive')
        ;
    });

    it('allows an active request body stream (upload) to complete before closing on shutdown', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);
        $wasClosed = false;

        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler($connection, function (Request $request, ProtocolHandlerInterface $protocol) {
            $protocol->writeResponse(Response::plaintext('Upload received'));
        });

        $handler->handleData("POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Length: 10\r\n\r\n");
        $handler->handleData('12345');

        $handler->gracefulShutdown();
        expect($wasClosed)->toBeFalse();

        $handler->handleData('67890');

        expect($wasClosed)->toBeTrue();
        expect($buffer)->toContain('Upload received');
        expect($buffer)->toContain('Connection: close');
    });

    it('gracefully closes connection after a streaming response (download) completes if shutdown triggered mid-stream', function () {
        $buffer = '';
        $connection = mockStreamingConnection($buffer);
        $wasClosed = false;

        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $stream = new ThroughStream();

        $handler = new Http11ProtocolHandler($connection, function (Request $request, ProtocolHandlerInterface $protocol) use ($stream) {
            $protocol->writeResponse(new Response(200, [], $stream));
        });

        $handler->handleData("GET /stream HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $stream->write("chunk1\n");

        $handler->gracefulShutdown();

        expect($wasClosed)->toBeFalse();

        $stream->write("chunk2\n");
        $stream->end();

        expect($wasClosed)->toBeTrue();
        expect($buffer)->toContain("chunk1\n")
            ->and($buffer)->toContain("chunk2\n")
            ->and($buffer)->toContain("0\r\n\r\n")
        ;
    });

    it('immediately closes connections that are mid-header-upload during shutdown', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);
        $wasClosed = false;

        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler($connection, function () {});

        // Send a partial request line (client is dripping headers slowly)
        $handler->handleData("GET /slow-path HT");

        $handler->gracefulShutdown();

        expect($wasClosed)->toBeTrue();
    });
});
