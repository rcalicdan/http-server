<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

use function Hibla\await;
use function Hibla\delay;

afterEach(function () {
    Loop::reset();
});

describe('Protocol Handler Timeouts', function () {

    it('closes the connection with 408 Request Timeout if headers arrive too slowly', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function () {
            },
            headerTimeout: 0.1
        );

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n");

        await(delay(0.15));

        expect($buffer)->toContain('HTTP/1.1 408 Request Timeout')
            ->and($buffer)->toContain('Connection: close')
        ;
    });

    it('cancels the header timeout if the request completes successfully', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'OK'));
            },
            headerTimeout: 0.1
        );

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        await(delay(0.15));

        expect($buffer)->not->toContain('408 Request Timeout')
            ->and($buffer)->toContain('HTTP/1.1 200 OK')
        ;
    });

    it('closes idle connections after the keep-alive timeout expires', function () {
        $buffer = '';
        $wasClosed = false;

        $connection = mockConnection($buffer);
        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'OK'));
            },
            keepAliveTimeout: 0.1
        );

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        expect($buffer)->toContain('HTTP/1.1 200 OK');

        expect($wasClosed)->toBeFalse();

        await(delay(0.15));

        expect($wasClosed)->toBeTrue();
    });

    it('closes the connection if client remains silent after initial connection', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function () {
            },
            headerTimeout: 0.1
        );

        await(delay(0.15));

        expect($buffer)->toContain('HTTP/1.1 408 Request Timeout')
            ->and($buffer)->toContain('Connection: close')
        ;
    });

    it('cancels keep-alive timer and transitions to header timeout when next request first byte arrives', function () {
        $buffer = '';
        $wasClosed = false;

        $connection = mockConnection($buffer);
        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'OK'));
            },
            headerTimeout: 0.1,
            keepAliveTimeout: 0.1
        );

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        expect($buffer)->toContain('HTTP/1.1 200 OK');
        $buffer = '';

        await(delay(0.05));
        expect($wasClosed)->toBeFalse();

        $handler->handleData('G');

        await(delay(0.07));
        expect($wasClosed)->toBeFalse();

        await(delay(0.05));

        expect($buffer)->toContain('HTTP/1.1 408 Request Timeout')
            ->and($buffer)->toContain('Connection: close')
        ;
    });

    it('starts the header timeout immediately for pipelined requests waiting in the buffer', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'OK'));
            },
            headerTimeout: 0.1
        );

        $handler->handleData(
            "GET /first HTTP/1.1\r\nHost: localhost\r\n\r\n" .
            "GET /second HTTP/1.1\r\nHost: localhost"
        );

        expect($buffer)->toContain('HTTP/1.1 200 OK');
        $buffer = '';

        await(delay(0.15));

        expect($buffer)->toContain('HTTP/1.1 408 Request Timeout')
            ->and($buffer)->toContain('Connection: close')
        ;
    });
});
