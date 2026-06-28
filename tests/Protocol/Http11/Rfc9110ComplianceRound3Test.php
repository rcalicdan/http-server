<?php

declare(strict_types=1);

use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

describe('RFC 9112 section 7.1 — Strict Chunk Size Parsing', function () {

    it('rejects a chunk size containing non-hex characters (e.g., 5Z) to prevent TE.TE smuggling', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "5Z\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

});

describe('RFC 9112 section 6.1 — TE and Content-Length mandatory connection closure', function () {

    it('forces connection closure when both Transfer-Encoding and Content-Length are present', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler($connection, function (Request $request, ProtocolHandlerInterface $protocol) {
            $protocol->writeResponse(new Response(200, [], 'OK'));
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Content-Length: 5\r\n"
            . "\r\n"
            . "5\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($buffer)->toContain('Connection: close');
    });

});

describe('RFC 9112 section 6.3 — Transfer-Encoding final coding status code', function () {

    it('responds with 400 Bad Request (not 501 Not Implemented) if the TE chain does not end in chunked', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked, gzip\r\n"
            . "\r\n";

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($buffer)->not->toContain('501')
            ->and($requestReached)->toBeFalse()
        ;
    });

});

describe('RFC 9112 section 3.2 — Host Header list injection', function () {

    it('rejects a comma-separated Host header field to prevent routing bypass attacks', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: a.com, b.com\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

});

describe('RFC 9112 section 3.1 & 5.5 — Token and VCHAR strictness', function () {

    it('rejects a request method containing illegal control characters', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        // \x00 is a null byte, which is an illegal control character in an HTTP method token
        $handler->handleData("GE\x00T / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a header field value containing illegal control characters', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        // \x0B is a vertical tab, which is an illegal control character in a field value
        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nX-Custom: val\x0Bue\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

});

describe('RFC 9112 sectio 6.1 — HTTP/1.0 with Transfer-Encoding must force connection closure', function () {

    it('forces connection closure for an HTTP/1.0 request carrying Transfer-Encoding even when Connection: keep-alive is requested', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestCount = 0;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) use (&$requestCount) {
                $requestCount++;
                $protocol->writeResponse(new Response(200, [], 'OK'));
            }
        );

        $raw = "POST / HTTP/1.0\r\nConnection: keep-alive\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "5\r\nhello\r\n0\r\n\r\n"
            . "GET /second HTTP/1.0\r\n\r\n";

        $handler->handleData($raw);

        expect($requestCount)->toBe(1);
    });

});

describe('RFC 9110 section 10.1.1 — unknown Expect directive handling', function () {

    it('silently ignores an unrecognized Expect directive without sending 417', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nExpect: unknown-directive\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($buffer)->not->toContain('417')
        ;
    });

});
