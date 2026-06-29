<?php

declare(strict_types=1);

use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\RequestBodyStream;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

it('parses a basic GET request with no body', function () {
    $buffer = '';
    $connection = mockConnection($buffer);

    /** @var Request|null $parsedRequest */
    $parsedRequest = null;
    $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
        $parsedRequest = $request;
    });

    $raw = "GET /index.html?page=2 HTTP/1.1\r\n" .
        "Host: localhost\r\n" .
        "User-Agent: pest\r\n\r\n";

    $handler->handleData($raw);

    expect($parsedRequest)->not->toBeNull()
        ->and($parsedRequest->getMethod())->toBe('GET')
        ->and($parsedRequest->getUri())->toBe('/index.html?page=2')
        ->and($parsedRequest->getHeaderLine('host'))->toBe('localhost')
        ->and($parsedRequest->getProtocolVersion())->toBe('1.1')
        ->and($parsedRequest->getBody())->toBe('')
    ;
});

it('successfully parses requests delivered across multiple TCP packets', function () {
    $buffer = '';
    $connection = mockConnection($buffer);

    /** @var Request|null $parsedRequest */
    $parsedRequest = null;
    $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
        $parsedRequest = $request;
    });

    $handler->handleData("POST /submit HTTP/1.1\r\nHost: local");
    expect($parsedRequest)->toBeNull();

    $handler->handleData("host\r\nContent-Length: 10\r\n\r\nhello");
    expect($parsedRequest)->toBeNull();

    $handler->handleData('world');

    expect($parsedRequest)->not->toBeNull()
        ->and($parsedRequest->getBody())->toBe('helloworld')
    ;
});

it('parses chunked transfer encoding payloads correctly', function () {
    $buffer = '';
    $connection = mockConnection($buffer);

    /** @var Request|null $parsedRequest */
    $parsedRequest = null;
    $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
        $parsedRequest = $request;
    });

    $raw = "POST /chunked HTTP/1.1\r\n" .
        "Host: localhost\r\n" .
        "Transfer-Encoding: chunked\r\n\r\n" .
        "5\r\n" .
        "hello\r\n" .
        "6\r\n" .
        " world\r\n" .
        "0\r\n\r\n";

    $handler->handleData($raw);

    expect($parsedRequest)->not->toBeNull()
        ->and($parsedRequest->getBody())->toBe('hello world')
    ;
});

it('rejects and responds with a 431 status code if headers are too large', function () {
    $writtenBuffer = '';
    $connection = mockConnection($writtenBuffer);

    $handler = new Http11ProtocolHandler($connection, function () {
    });

    // Uses 20000 bytes to comfortably exceed the default 16KB limit in the handler
    $handler->handleData(str_repeat('X', 20000));

    expect($writtenBuffer)->toContain('HTTP/1.1 431 Request Header Fields Too Large')
        ->and($writtenBuffer)->toContain('Connection: close')
    ;
});

it('rejects and responds with a 413 status code if content-length exceeds max limit', function () {
    $writtenBuffer = '';
    $connection = mockConnection($writtenBuffer);

    $handler = new Http11ProtocolHandler($connection, function () {
    }, maxBodySize: 10);

    $raw = "POST /upload HTTP/1.1\r\n"
        . "Host: localhost\r\n"
        . "Content-Length: 100\r\n\r\n";

    $handler->handleData($raw);

    expect($writtenBuffer)->toContain('HTTP/1.1 413 Payload Too Large');
});

it('yields unparsed trailing bytes when detached for websocket/protocol upgrades', function () {
    $buffer = '';
    $connection = mockConnection($buffer);

    $handler = new Http11ProtocolHandler($connection, function () {
    });

    $handler->handleData("GET /chat HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\n\r\n<ws-binary-frame-data>");

    $trailingBytes = $handler->detach();

    expect($trailingBytes)->toBe('<ws-binary-frame-data>');
});

it('correctly formats and writes response structures to the connection', function () {
    $writtenBuffer = '';
    $connection = mockConnection($writtenBuffer);

    $handler = new Http11ProtocolHandler($connection, function () {
    });

    $response = new Response(201, [
        'Content-Type' => 'text/html',
        'X-Powered-By' => 'PestTests',
    ], '<h1>Success</h1>');

    $handler->writeResponse($response);

    expect($writtenBuffer)->toContain('HTTP/1.1 201 Created')
        ->and($writtenBuffer)->toContain('Content-Type: text/html')
        ->and($writtenBuffer)->toContain('X-Powered-By: PestTests')
        ->and($writtenBuffer)->toContain('Content-Length: 16')
        ->and($writtenBuffer)->toContain("\r\n\r\n<h1>Success</h1>")
    ;
});

it('handles pipelined requests sequentially in a single TCP stream payload', function () {
    $buffer = '';
    $connection = mockConnection($buffer);

    /** @var array<Request> $parsedRequests */
    $parsedRequests = [];
    $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequests) {
        $parsedRequests[] = $request;
    });

    $rawPayload = "GET /first-page HTTP/1.1\r\nHost: localhost\r\n\r\n" .
        "POST /second-page HTTP/1.1\r\nHost: localhost\r\nContent-Length: 4\r\n\r\nbody";

    $handler->handleData($rawPayload);

    expect($parsedRequests)->toHaveCount(2)
        ->and($parsedRequests[0]->getMethod())->toBe('GET')
        ->and($parsedRequests[0]->getUri())->toBe('/first-page')
        ->and($parsedRequests[1]->getMethod())->toBe('POST')
        ->and($parsedRequests[1]->getUri())->toBe('/second-page')
        ->and($parsedRequests[1]->getBody())->toBe('body')
    ;
});

it('triggers onRequest immediately when streamingRequests is enabled, then pipes the body dynamically', function () {
    $buffer = '';
    // Uses the pre-configured mock streaming connection
    $connection = mockStreamingConnection($buffer);

    /** @var Request|null $parsedRequest */
    $parsedRequest = null;
    $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
        $parsedRequest = $request;
    }, streamingRequests: true);

    $handler->handleData("POST /stream-endpoint HTTP/1.1\r\nHost: localhost\r\nContent-Length: 10\r\n\r\n");

    expect($parsedRequest)->not->toBeNull()
        ->and($parsedRequest->getBody())->toBeInstanceOf(RequestBodyStream::class)
    ;

    $streamedData = '';
    $parsedRequest->getBody()->on('data', function (string $chunk) use (&$streamedData) {
        $streamedData .= $chunk;
    });

    $handler->handleData('hello');
    expect($streamedData)->toBe('hello');

    $handler->handleData('world');
    expect($streamedData)->toBe('helloworld');
});

it('correctly trims and ignores chunk extensions inside chunked payloads', function () {
    $buffer = '';
    $connection = mockConnection($buffer);

    /** @var Request|null $parsedRequest */
    $parsedRequest = null;
    $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
        $parsedRequest = $request;
    });

    $raw = "POST /chunked HTTP/1.1\r\n" .
        "Host: localhost\r\n" .
        "Transfer-Encoding: chunked\r\n\r\n" .
        "5;key=value;another=param\r\n" .
        "chunk\r\n" .
        "0\r\n\r\n";

    $handler->handleData($raw);

    expect($parsedRequest)->not->toBeNull()
        ->and($parsedRequest->getBody())->toBe('chunk')
    ;
});

it('responds with 400 Bad Request and terminates when the request line is structurally malformed', function () {
    $writtenBuffer = '';
    $connection = mockConnection($writtenBuffer);

    $handler = new Http11ProtocolHandler($connection, function () {
    });

    $handler->handleData("GET HTTP/1.1\r\nHost: localhost\r\n\r\n");

    expect($writtenBuffer)->toContain('HTTP/1.1 400 Bad Request')
        ->and($writtenBuffer)->toContain('Connection: close')
    ;
});

it('automatically sends a 100 Continue response when the Expect header is present', function () {
    $writtenBuffer = '';
    $connection = mockConnection($writtenBuffer);

    /** @var Request|null $parsedRequest */
    $parsedRequest = null;
    $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
        $parsedRequest = $request;
    });

    $rawHeaders = "POST /upload HTTP/1.1\r\n" .
        "Host: localhost\r\n" .
        "Content-Length: 11\r\n" .
        "Expect: 100-continue\r\n\r\n";

    $handler->handleData($rawHeaders);

    expect($writtenBuffer)->toBe("HTTP/1.1 100 Continue\r\n\r\n")
        ->and($parsedRequest)->toBeNull()
    ;

    $handler->handleData('hello world');

    expect($parsedRequest)->not->toBeNull()
        ->and($parsedRequest->getBody())->toBe('hello world')
    ;
});
