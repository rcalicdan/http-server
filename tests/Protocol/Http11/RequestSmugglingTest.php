<?php

declare(strict_types=1);

use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

describe('TE obfuscation via casing — TE.TE bypass prevention', function () {

    it('normalizes Transfer-Encoding: Chunked (capital C) and parses the body correctly', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData(
            "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: Chunked\r\n\r\n"
            . "5\r\nhello\r\n0\r\n\r\n"
        );

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('hello')
        ;
    });

    it('normalizes Transfer-Encoding: CHUNKED (all caps) and parses the body correctly', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData(
            "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: CHUNKED\r\n\r\n"
            . "5\r\nhello\r\n0\r\n\r\n"
        );

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('hello')
        ;
    });

});

describe('Multi-header Transfer-Encoding chain termination — TE.TE desync prevention', function () {

    it('rejects a combined TE chain across two headers where the final coding is not chunked', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Transfer-Encoding: identity\r\n"
            . "Content-Length: 5\r\n"
            . "\r\nhello";

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('accepts a combined TE chain across two headers where the final coding is chunked', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: identity\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n5\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('hello')
        ;
    });

    it('accepts an unrecognized intermediate TE coding when the final coding is chunked', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: x-custom-coding, chunked\r\n"
            . "\r\n5\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('hello')
        ;
    });

});

describe('Content-Length desync vectors', function () {

    it('accepts a Content-Length with a leading zero and reads exactly that many bytes', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 05\r\n\r\nhello");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('hello')
        ;
    });

    it('rejects a Content-Length value containing an embedded space between digits', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 1 0\r\n\r\nhelloworld");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a Content-Length value prefixed with a plus sign', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: +5\r\n\r\nhello");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

});

describe('Pipelining after forced connection closure — smuggled request drop invariant', function () {

    it('does not parse a pipelined request that arrived in the buffer after a Connection: close response', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestCount = 0;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) use (&$requestCount) {
                $requestCount++;
                $protocol->writeResponse(new Response(200, ['Connection' => 'close'], 'bye'));
            }
        );

        $raw = "GET /first HTTP/1.1\r\nHost: localhost\r\n\r\n"
            . "GET /smuggled HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $handler->handleData($raw);

        expect($requestCount)->toBe(1);
    });

    it('does not parse a pipelined request that arrived after an HTTP/1.0 non-persistent response', function () {
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

        $raw = "GET /first HTTP/1.0\r\nHost: localhost\r\n\r\n"
            . "GET /smuggled HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $handler->handleData($raw);

        expect($requestCount)->toBe(1);
    });

});

describe('Control characters in header field values — header injection prevention', function () {

    it('rejects a Transfer-Encoding value containing a null byte', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chun\x00ked\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a Transfer-Encoding value containing a vertical tab', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chun\x0Bked\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a Content-Length value containing a null byte', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\x00\r\n\r\nhello");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });
});

describe('Transfer-Encoding — empty and degenerate chain values', function () {

    it('rejects a Transfer-Encoding header with a completely empty value', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: \r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a Transfer-Encoding header containing only commas and whitespace', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: , , ,\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

});

describe('Bare LF line terminators — parser stall behavior', function () {

    it('does not dispatch a request that uses bare LF terminators instead of CRLF', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\nHost: localhost\n\n");

        expect($requestReached)->toBeFalse()
            ->and($buffer)->toBe('');
    });

});
