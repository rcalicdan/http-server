<?php

declare(strict_types=1);

use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\RequestBodyStream;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

describe('Streaming — Chunked partial read: basic delivery', function () {

    it('emits chunk data immediately without waiting for the full chunk to arrive', function () {
        $buffer = '';
        $connection = mockStreamingConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request) use (&$parsedRequest) {
                $parsedRequest = $request;
            },
            streamingRequests: true
        );

        $handler->handleData(
            "POST /stream HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "b\r\n"
        );

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBeInstanceOf(RequestBodyStream::class)
        ;

        $received = '';
        $parsedRequest->getBody()->on('data', function (string $chunk) use (&$received) {
            $received .= $chunk;
        });

        $handler->handleData('hell');
        expect($received)->toBe('hell');

        $handler->handleData('o worl');
        expect($received)->toBe('hello worl');

        $handler->handleData('d');
        expect($received)->toBe('hello world');
    });

});

describe('Streaming — Chunked partial read: CRLF boundary split across TCP packets', function () {

    it('does not emit stale bytes when the CRLF trailing a chunk is split across two reads', function () {
        $buffer = '';
        $connection = mockStreamingConnection($buffer);

        $received = '';
        $ended = false;

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request) use (&$received, &$ended) {
                $request->getBody()->on('data', function (string $chunk) use (&$received) {
                    $received .= $chunk;
                });
                $request->getBody()->on('end', function () use (&$ended) {
                    $ended = true;
                });
            },
            streamingRequests: true
        );

        $handler->handleData(
            "POST /stream HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "5\r\nhello"
        );

        expect($received)->toBe('hello');
        expect($ended)->toBeFalse();

        $handler->handleData("\r");
        expect($received)->toBe('hello');
        expect($ended)->toBeFalse();

        $handler->handleData("\n0\r\n\r\n");
        expect($ended)->toBeTrue();
        expect($received)->toBe('hello');
    });

    it('does not emit the two CRLF bytes as body data when the trailing CRLF arrives in isolation', function () {
        $buffer = '';
        $connection = mockStreamingConnection($buffer);

        $received = '';

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request) use (&$received) {
                $request->getBody()->on('data', function (string $chunk) use (&$received) {
                    $received .= $chunk;
                });
            },
            streamingRequests: true
        );

        $handler->handleData(
            "POST /stream HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "3\r\nabc"
        );

        expect($received)->toBe('abc');

        $handler->handleData("\r\n0\r\n\r\n");

        expect($received)->toBe('abc');
    });

    it('handles the case where CR and LF of the trailing CRLF arrive in separate TCP reads', function () {
        $buffer = '';
        $connection = mockStreamingConnection($buffer);

        $received = '';
        $ended = false;

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request) use (&$received, &$ended) {
                $request->getBody()->on('data', function (string $chunk) use (&$received) {
                    $received .= $chunk;
                });
                $request->getBody()->on('end', function () use (&$ended) {
                    $ended = true;
                });
            },
            streamingRequests: true
        );

        $handler->handleData(
            "POST /stream HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "2\r\nhi"
        );

        expect($received)->toBe('hi');
        expect($ended)->toBeFalse();

        $handler->handleData("\r");
        expect($received)->toBe('hi');
        expect($ended)->toBeFalse();

        $handler->handleData("\n0\r\n\r\n");
        expect($ended)->toBeTrue();
        expect($received)->toBe('hi');
    });

});

describe('Streaming — Chunked partial read: multi-chunk sequencing', function () {

    it('emits bytes from multiple chunks in order as they arrive across many TCP reads', function () {
        $buffer = '';
        $connection = mockStreamingConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request) use (&$parsedRequest) {
                $parsedRequest = $request;
            },
            streamingRequests: true
        );

        $handler->handleData(
            "POST /stream HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
        );

        $received = '';
        $ended = false;
        $parsedRequest->getBody()->on('data', function (string $chunk) use (&$received) {
            $received .= $chunk;
        });
        $parsedRequest->getBody()->on('end', function () use (&$ended) {
            $ended = true;
        });

        $handler->handleData("4\r\nfoo");
        expect($received)->toBe('foo');

        $handler->handleData("d\r\n");
        expect($received)->toBe('food');

        $handler->handleData("6\r\n");
        $handler->handleData('bar');
        expect($received)->toBe('foodbar');

        $handler->handleData("baz\r\n");
        expect($received)->toBe('foodbarbaz');

        $handler->handleData("0\r\n\r\n");
        expect($ended)->toBeTrue();
    });

    it('correctly sequences chunk size lines that arrive byte-by-byte', function () {
        $buffer = '';
        $connection = mockStreamingConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request) use (&$parsedRequest) {
                $parsedRequest = $request;
            },
            streamingRequests: true
        );

        $handler->handleData(
            "POST /stream HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
        );

        $received = '';
        $parsedRequest->getBody()->on('data', function (string $chunk) use (&$received) {
            $received .= $chunk;
        });

        $handler->handleData('3');
        $handler->handleData("\r");
        $handler->handleData("\n");
        $handler->handleData('a');
        $handler->handleData('b');
        $handler->handleData('c');
        $handler->handleData("\r");
        $handler->handleData("\n");
        $handler->handleData('0');
        $handler->handleData("\r");
        $handler->handleData("\n");
        $handler->handleData("\r");
        $handler->handleData("\n");

        expect($received)->toBe('abc');
    });

});

describe('Streaming — Chunked partial read: end event integrity', function () {

    it('fires the end event exactly once after the terminal zero-chunk is consumed', function () {
        $buffer = '';
        $connection = mockStreamingConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request) use (&$parsedRequest) {
                $parsedRequest = $request;
            },
            streamingRequests: true
        );

        $handler->handleData(
            "POST /stream HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
        );

        $endCount = 0;
        $parsedRequest->getBody()->on('end', function () use (&$endCount) {
            $endCount++;
        });

        $handler->handleData("1\r\nX\r\n0\r\n\r\n");

        expect($endCount)->toBe(1);
    });

    it('fires the end event exactly once even when the terminal chunk arrives byte-by-byte', function () {
        $buffer = '';
        $connection = mockStreamingConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request) use (&$parsedRequest) {
                $parsedRequest = $request;
            },
            streamingRequests: true
        );

        $handler->handleData(
            "POST /stream HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
        );

        $endCount = 0;
        $parsedRequest->getBody()->on('end', function () use (&$endCount) {
            $endCount++;
        });

        $handler->handleData("1\r\nX\r\n");

        $handler->handleData('0');
        $handler->handleData("\r");
        $handler->handleData("\n");
        $handler->handleData("\r");
        $handler->handleData("\n");

        expect($endCount)->toBe(1);
    });

});
