<?php

declare(strict_types=1);

use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

describe('RFC 9112 section 6.1/6.3 — Transfer-Encoding precedence beyond literal "chunked"', function () {

    it('does not fall back to Content-Length framing when Transfer-Encoding names an unrecognized/non-chunked coding', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: identity\r\n"
            . "Content-Length: 5\r\n"
            . "\r\n"
            . 'hello';

        $handler->handleData($raw);

        expect($buffer)->toContain('501')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('strips Content-Length even when Transfer-Encoding resolves to something other than chunked', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: gzip\r\n"
            . "Content-Length: 5\r\n"
            . "\r\n"
            . 'hello';

        $handler->handleData($raw);

        if ($parsedRequest !== null) {
            expect($parsedRequest->hasHeader('content-length'))->toBeFalse();
        } else {
            expect($buffer)->not->toContain('200');
        }
    });

});

describe('RFC 9112 section 6.3 / RFC 9110 section 8.6 — Content-Length value validation', function () {

    it('rejects a Content-Length value containing non-digit characters', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 10abc\r\n\r\nhelloworld");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a negative Content-Length value instead of silently treating the request as bodyless', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: -5\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

});

describe('RFC 9112 section 2.2 — Bare CR handling', function () {

    it('rejects a header field value containing a bare CR not followed by LF', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nX-Custom: foo\rbar\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

});

describe('RFC 9112 section 5.1 — malformed field-line grammar', function () {

    it('rejects a header line with no colon at all rather than silently skipping it', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nThisIsNotAValidHeaderLine\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

});

describe('RFC 9112 section 2.2 / section 3 — Header size enforcement must not depend on read fragmentation', function () {

    it('rejects oversized headers even when the full header block (with terminator) arrives in a single read', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $oversizedValue = str_repeat('A', 9000);
        $raw = "GET / HTTP/1.1\r\nHost: localhost\r\nX-Big: {$oversizedValue}\r\n\r\n";

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 431 Request Header Fields Too Large')
            ->and($requestReached)->toBeFalse()
        ;
    });

});

describe('RFC 9112 section 7.1 — chunk-size line has no size bound', function () {

    it('eventually rejects an absurdly long chunk-size line instead of buffering it indefinitely', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $handler = new Http11ProtocolHandler($connection, function () {
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . str_repeat('A', 100000);

        $handler->handleData($raw);

        expect($buffer)->toContain('400');
    });

});

describe('Error-state continuation bug — chunked body exceeding maxBodySize', function () {

    it('does not invoke the request handler after a 413 has already been sent mid-chunked-body', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $requestCount = 0;
        $handler = new Http11ProtocolHandler(
            $connection,
            function () use (&$requestCount) {
                $requestCount++;
            },
            maxBodySize: 5
        );

        $raw = "POST /upload HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "3\r\nabc\r\n"
            . "6\r\ndefghi\r\n"
            . "0\r\n\r\n";

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 413 Payload Too Large')
            ->and($requestCount)->toBe(0)
        ;
    });

});
