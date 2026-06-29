<?php

declare(strict_types=1);

use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;
use Hibla\Socket\Interfaces\ConnectionInterface;

describe('RFC 9112 section 2.2 — Message Parsing Robustness', function () {

    it('tolerates at least one leading CRLF before the request-line', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("\r\nGET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getMethod())->toBe('GET')
            ->and($buffer)->not->toContain('400')
        ;
    });

    it('tolerates multiple leading CRLFs before the request-line', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("\r\n\r\n\r\nGET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($buffer)->not->toContain('400')
        ;
    });
});

describe('RFC 9112 section 2.3 — HTTP Version', function () {

    it('rejects a request with a lowercase http-version (version is case-sensitive)', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / http/1.1\r\nHost: localhost\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a request with a non-HTTP version prefix', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / FTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a request where the version has no minor digit (e.g. HTTP/1)', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1\r\nHost: localhost\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });
});

describe('RFC 9112 section 3.2 — Host Header', function () {

    it('rejects an HTTP/1.1 request that has no Host header with 400', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects an HTTP/1.1 request that has more than one Host header line with 400', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: foo.com\r\nHost: bar.com\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });
});

describe('RFC 9112 section 5.1 — Field Line Parsing', function () {

    it('rejects a request where a header name has trailing whitespace before the colon', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nContent-Type : text/html\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a request where a header name has multiple spaces before the colon', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nX-Custom   : value\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });
});

describe('RFC 9112 section 5.2 — Obsolete Line Folding', function () {

    it('rejects a request that contains obsolete header line folding (obs-fold)', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nX-Long-Header: first-part\r\n second-part\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a request that contains obs-fold using a tab as the continuation marker', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nX-Long-Header: first-part\r\n\tsecond-part\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });
});

describe('RFC 9112 section 6.2 — Content-Length', function () {

    it('rejects a request with multiple conflicting Content-Length values', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\nContent-Length: 10\r\n\r\nhello");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('accepts a request with multiple identical Content-Length values (deduplication)', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\nContent-Length: 5\r\n\r\nhello");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('hello')
        ;
    });
});

describe('RFC 9112 section 6.1 / section 6.3 — Transfer-Encoding and Content-Length', function () {

    it('strips Content-Length from the request when Transfer-Encoding is also present', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Content-Length: 5\r\n"
            . "\r\n"
            . "5\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->hasHeader('content-length'))->toBeFalse()
            ->and($parsedRequest->getBody())->toBe('hello')
        ;
    });

    it('detects chunked as the active coding when Transfer-Encoding is a comma-separated list', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: identity, chunked\r\n"
            . "\r\n"
            . "5\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('hello')
        ;
    });
});

describe('RFC 9112 section 7.1.2 — Chunked Trailer Section', function () {

    it('correctly skips chunked trailers without corrupting a subsequent pipelined request', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequests = [];
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequests) {
            $parsedRequests[] = $request;
        });

        $raw = "POST /first HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Trailer: X-Checksum\r\n"
            . "\r\n"
            . "5\r\nhello\r\n"
            . "0\r\n"
            . "X-Checksum: abc123\r\n"   // trailer field
            . "\r\n"
            . "GET /second HTTP/1.1\r\n" // pipelined request must parse cleanly
            . "Host: localhost\r\n"
            . "\r\n";

        $handler->handleData($raw);

        expect($parsedRequests)->toHaveCount(2)
            ->and($parsedRequests[0]->getUri())->toBe('/first')
            ->and($parsedRequests[0]->getBody())->toBe('hello')
            ->and($parsedRequests[1]->getUri())->toBe('/second')
            ->and($buffer)->not->toContain('400')
        ;
    });

    it('correctly skips chunked trailers when the body has no continuation request', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "5\r\nhello\r\n"
            . "0\r\n"
            . "X-Trailer: some-value\r\n"
            . "\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('hello')
            ->and($buffer)->not->toContain('400')
        ;
    });
});

describe('RFC 9112 section 9.3 — Connection Persistence', function () {

    it('closes the connection after responding to an HTTP/1.0 request (non-persistent by default)', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'OK'));
            }
        );

        $handler->handleData("GET / HTTP/1.0\r\nHost: localhost\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.0 200 OK');
    });

    it('keeps the connection open after responding to an HTTP/1.0 request with Connection: keep-alive', function () {
        $buffer = '';
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

        $connection->shouldReceive('on')->zeroOrMoreTimes();

        $connection->shouldReceive('write')->andReturnUsing(function (string $data) use (&$buffer) {
            $buffer .= $data;

            return true;
        });
        $connection->shouldReceive('close')->never();

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'OK'));
            }
        );

        $handler->handleData("GET / HTTP/1.0\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");

        expect($buffer)->toContain('200');
    });
});

describe('RFC 9112 section 9.6 — Connection Tear-down', function () {

    it('closes the connection when the response itself carries a Connection: close header', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(
                    new Response(200, ['Connection' => 'close'], 'Goodbye')
                );
            }
        );

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($buffer)->toContain('Connection: close');
    });
});
