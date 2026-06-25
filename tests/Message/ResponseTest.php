<?php

declare(strict_types=1);

use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Message\SseStream;
use Hibla\Stream\Interfaces\ReadableStreamInterface;

it('automatically sets the correct reason phrase', function () {
    $responseOk = new Response(200);
    expect($responseOk->getStatusCode())->toBe(200)
        ->and($responseOk->getReasonPhrase())->toBe('OK')
    ;

    $responseNotFound = new Response(404);
    expect($responseNotFound->getStatusCode())->toBe(404)
        ->and($responseNotFound->getReasonPhrase())->toBe('Not Found')
    ;

    $responseCustom = new Response(418, [], '', 'I am a coffee pot');
    expect($responseCustom->getReasonPhrase())->toBe('I am a coffee pot');
});

it('normalizes headers on instantiation', function () {
    $response = new Response(200, [
        'Content-Type' => 'text/plain',
        'X-Multiple' => ['A', 'B'],
    ]);

    $headers = $response->getHeaders();

    expect($headers)->toHaveKey('content-type')
        ->and($headers['content-type'])->toBe(['text/plain'])
        ->and($headers['x-multiple'])->toBe(['A', 'B'])
    ;
});

it('can overwrite headers via setHeader', function () {
    $response = new Response(200, ['Content-Type' => 'text/html']);

    $response->setHeader('content-type', 'application/json');
    $response->setHeader('X-New', ['1', '2']);

    expect($response->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($response->getHeaderLine('X-New'))->toBe('1, 2')
    ;
});

it('can append values to existing headers via addHeader', function () {
    $response = new Response(200, ['Set-Cookie' => 'session=123']);

    $response->addHeader('Set-Cookie', 'theme=dark');
    $response->addHeader('Set-Cookie', ['lang=en', 'track=0']);

    expect($response->getHeaders()['set-cookie'])->toBe([
        'session=123',
        'theme=dark',
        'lang=en',
        'track=0',
    ]);
});

it('creates plaintext responses via factory', function () {
    $response = Response::plaintext('Hello World', 201);

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getHeaderLine('Content-Type'))->toBe('text/plain; charset=utf-8')
        ->and($response->getBody())->toBe('Hello World')
    ;
});

it('creates json responses via factory', function () {
    $data = ['id' => 1, 'name' => 'Test'];
    $response = Response::json($data, 200);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($response->getBody())->toContain('"name": "Test"')
    ;
});

it('throws an exception on invalid json data', function () {
    $resource = fopen('php://memory', 'r');

    expect(fn () => Response::json($resource))
        ->toThrow(InvalidArgumentException::class, 'Unable to encode given data as JSON')
    ;
});

it('creates html responses via factory', function () {
    $html = '<h1>Title</h1>';
    $response = Response::html($html, 403);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getHeaderLine('Content-Type'))->toBe('text/html; charset=utf-8')
        ->and($response->getBody())->toBe($html)
    ;
});

it('falls back to Unknown for unrecognized status codes', function () {
    $response = new Response(999);

    expect($response->getStatusCode())->toBe(999)
        ->and($response->getReasonPhrase())->toBe('Unknown')
    ;
});

it('completely overwrites existing values when using setHeader', function () {
    $response = new Response(200, ['Cache-Control' => 'public, max-age=3600']);

    $response->setHeader('Cache-Control', 'no-store');
    expect($response->getHeader('cache-control'))->toBe(['no-store']);

    $response->setHeader('Cache-Control', ['no-cache', 'must-revalidate']);
    expect($response->getHeader('cache-control'))->toBe(['no-cache', 'must-revalidate']);
});

it('creates the header if it does not exist when using addHeader', function () {
    $response = new Response(200);

    expect($response->getHeaders())->toBeEmpty();

    $response->addHeader('X-New-Header', 'FirstValue');

    expect($response->getHeaderLine('X-New-Header'))->toBe('FirstValue');
});

it('creates valid Server-Sent Events (SSE) responses via factory', function () {
    $response = Response::sse(function (SseStream $stream) {
        // Dummy emitter
    });

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('text/event-stream')
        ->and($response->getHeaderLine('Cache-Control'))->toBe('no-cache')
        ->and($response->getHeaderLine('Connection'))->toBe('keep-alive')
        ->and($response->getHeaderLine('X-Accel-Buffering'))->toBe('no')
    ;

    expect($response->getBody())->toBeInstanceOf(SseStream::class)
        ->and($response->getBody()->isReadable())->toBeTrue()
    ;
});

it('can accept a readable stream as a response body', function () {
    $dummyStream = Mockery::mock(ReadableStreamInterface::class);

    $response = new Response();
    $response->setBody($dummyStream);

    expect($response->getBody())->toBeInstanceOf(ReadableStreamInterface::class)
        ->and($response->getBody())->toBe($dummyStream);
});
