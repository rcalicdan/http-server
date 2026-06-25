<?php

declare(strict_types=1);

use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\RequestBodyStream;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;
use Hibla\Socket\Interfaces\ConnectionInterface;

it('parses a basic GET request with no body', function () {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

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
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

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
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

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
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

    $writtenBuffer = '';
    $connection->shouldReceive('write')->andReturnUsing(function (string $data) use (&$writtenBuffer) {
        $writtenBuffer .= $data;

        return true;
    });
    $connection->shouldReceive('close')->once();

    $handler = new Http11ProtocolHandler($connection, function () {
    });

    $handler->handleData(str_repeat('X', 9000));

    expect($writtenBuffer)->toContain('HTTP/1.1 431 Request Header Fields Too Large')
        ->and($writtenBuffer)->toContain('Connection: close')
    ;
});

it('rejects and responds with a 413 status code if content-length exceeds max limit', function () {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

    $writtenBuffer = '';
    $connection->shouldReceive('write')->andReturnUsing(function (string $data) use (&$writtenBuffer) {
        $writtenBuffer .= $data;

        return true;
    });
    $connection->shouldReceive('close')->once();

    $handler = new Http11ProtocolHandler($connection, function () {
    }, maxBodySize: 10);

    $raw = "POST /upload HTTP/1.1\r\n"
        . "Host: localhost\r\n"
        . "Content-Length: 100\r\n\r\n";

    $handler->handleData($raw);

    expect($writtenBuffer)->toContain('HTTP/1.1 413 Payload Too Large');
});

it('yields unparsed trailing bytes when detached for websocket/protocol upgrades', function () {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

    $handler = new Http11ProtocolHandler($connection, function () {
    });

    $handler->handleData("GET /chat HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\n\r\n<ws-binary-frame-data>");

    $trailingBytes = $handler->detach();

    expect($trailingBytes)->toBe('<ws-binary-frame-data>');
});

it('correctly formats and writes response structures to the connection', function () {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

    $writtenBuffer = '';
    $connection->shouldReceive('write')->andReturnUsing(function (string $data) use (&$writtenBuffer) {
        $writtenBuffer .= $data;

        return true;
    });

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
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

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
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');
    $connection->shouldReceive('pause');
    $connection->shouldReceive('resume');

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
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

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
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

    $writtenBuffer = '';
    $connection->shouldReceive('write')->andReturnUsing(function (string $data) use (&$writtenBuffer) {
        $writtenBuffer .= $data;

        return true;
    });
    $connection->shouldReceive('close')->once();

    $handler = new Http11ProtocolHandler($connection, function () {
    });

    $handler->handleData("GET HTTP/1.1\r\nHost: localhost\r\n\r\n");

    expect($writtenBuffer)->toContain('HTTP/1.1 400 Bad Request')
        ->and($writtenBuffer)->toContain('Connection: close')
    ;
});
