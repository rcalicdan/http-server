<?php

declare(strict_types=1);

use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

describe('RFC 9931 Section 8 — Requirements for HTTP CONNECT', function () {

    it('MUST close the connection when rejecting a CONNECT request to mitigate optimistic smuggling', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        /** @var array<Request> $parsedRequests */
        $parsedRequests = [];

        $handler = new Http11ProtocolHandler($connection, function (Request $request, ProtocolHandlerInterface $protocol) use (&$parsedRequests) {
            $parsedRequests[] = $request;

            if ($request->getMethod() === 'CONNECT') {
                $protocol->writeResponse(new Response(403, [], 'Forbidden'));
            }
        });

        $raw = "CONNECT target.example:443 HTTP/1.1\r\n"
             . "Host: target.example:443\r\n"
             . "\r\n"
             . "POST /smuggled-endpoint HTTP/1.1\r\n"
             . "Host: localhost\r\n"
             . "Content-Length: 0\r\n\r\n";

        $handler->handleData($raw);
        expect($buffer)->toContain('Connection: close');
        ! expect($parsedRequests)->toHaveCount(1)
                    ->and($parsedRequests[0]->getMethod())->toBe('CONNECT')
        ;
    });

});

describe('RFC 9931 Section 8 — CONNECT with a request body', function () {

    it('discards a pipelined request arriving after a rejected CONNECT that carried a chunked body', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestCount = 0;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) use (&$requestCount) {
                $requestCount++;
                $protocol->writeResponse(new Response(405, [], 'Method Not Allowed'));
            }
        );

        $raw = "CONNECT example.com:443 HTTP/1.1\r\nHost: example.com\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "5\r\nhello\r\n0\r\n\r\n"
            . "GET /smuggled HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $handler->handleData($raw);

        expect($requestCount)->toBe(1)
            ->and($buffer)->toContain('HTTP/1.1 405')
        ;
    });

});
