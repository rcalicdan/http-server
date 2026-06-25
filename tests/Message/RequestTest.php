<?php

declare(strict_types=1);

use Hibla\HttpServer\Message\Request;
use Hibla\Stream\Interfaces\ReadableStreamInterface;

it('correctly assigns and retrieves constructor values', function () {
    $request = new Request(
        method: 'POST',
        uri: '/api/users',
        headers: ['content-type' => ['application/json']],
        body: '{"name": "John"}',
        protocolVersion: '1.0',
        serverParams: ['REMOTE_ADDR' => '127.0.0.1']
    );

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri())->toBe('/api/users')
        ->and($request->getProtocolVersion())->toBe('1.0')
        ->and($request->getBody())->toBe('{"name": "John"}')
        ->and($request->getServerParams())->toBe(['REMOTE_ADDR' => '127.0.0.1'])
    ;
});

it('handles headers case-insensitively', function () {
    $request = new Request(
        method: 'GET',
        uri: '/',
        headers: [
            'content-type' => ['text/html'],
            'X-Custom-Header' => ['Value1', 'Value2'],
        ]
    );

    expect($request->hasHeader('content-type'))->toBeTrue()
        ->and($request->getHeader('content-type'))->toBe(['text/html'])
    ;

    expect($request->hasHeader('Content-Type'))->toBeTrue()
        ->and($request->getHeader('X-CUSTOM-HEADER'))->toBe(['Value1', 'Value2'])
        ->and($request->hasHeader('Authorization'))->toBeFalse()
    ;
});

it('formats header lines correctly', function () {
    $request = new Request('GET', '/', [
        'accept' => ['text/html', 'application/xhtml+xml'],
    ]);

    expect($request->getHeaderLine('Accept'))->toBe('text/html, application/xhtml+xml')
        ->and($request->getHeaderLine('Non-Existent'))->toBe('')
    ;
});

it('can mutate the body payload', function () {
    $request = new Request('GET', '/');

    expect($request->getBody())->toBe('');

    $request->setBody('New Payload');
    expect($request->getBody())->toBe('New Payload');
});

it('combines multiple header values properly in getHeaderLine', function () {
    $request = new Request('GET', '/', [
        'X-Forwarded-For' => ['192.168.1.1', '10.0.0.1'],
    ]);

    expect($request->getHeaderLine('x-forwarded-for'))->toBe('192.168.1.1, 10.0.0.1');
});

it('handles non-existent headers gracefully', function () {
    $request = new Request('GET', '/');

    expect($request->hasHeader('X-Missing'))->toBeFalse()
        ->and($request->getHeader('X-Missing'))->toBeArray()->toBeEmpty()
        ->and($request->getHeaderLine('X-Missing'))->toBe('')
    ;
});

it('handles numeric or weird header keys correctly', function () {
    $request = new Request('GET', '/', [
        123 => ['Numeric Key'],
        '  SPACED-KEY  ' => ['Spaced Value'],
    ]);

    expect($request->hasHeader('123'))->toBeTrue()
        ->and($request->getHeaderLine('123'))->toBe('Numeric Key')
        ->and($request->getHeaderLine('  spaced-key  '))->toBe('Spaced Value')
    ;
});

it('can accept a readable stream object as the body', function () {
    $dummyStream = Mockery::mock(ReadableStreamInterface::class);

    $request = new Request('POST', '/');
    $request->setBody($dummyStream);

    expect($request->getBody())->toBeInstanceOf(ReadableStreamInterface::class)
        ->and($request->getBody())->toBe($dummyStream);
});
