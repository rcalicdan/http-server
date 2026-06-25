<?php

declare(strict_types=1);

use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Tests\Fixtures\ConcreteMessage;

it('handles body mutations correctly', function () {
    $message = new ConcreteMessage(body: 'Original Body');

    expect($message->getBody())->toBe('Original Body');

    $message->setBody('Updated Body');
    expect($message->getBody())->toBe('Updated Body');
});

it('accepts and retrieves stream objects as body', function () {
    $streamMock = Mockery::mock(ReadableStreamInterface::class);
    $message = new ConcreteMessage();

    $message->setBody($streamMock);

    expect($message->getBody())->toBeInstanceOf(ReadableStreamInterface::class)
        ->and($message->getBody())->toBe($streamMock)
    ;
});

it('manages protocol versions', function () {
    $message = new ConcreteMessage(protocolVersion: '2.0');

    expect($message->getProtocolVersion())->toBe('2.0');
});

it('performs case-insensitive header lookups', function () {
    $message = new ConcreteMessage([
        'X-API-Key' => ['secret123'],
        'Accept' => 'application/json',
    ]);

    expect($message->hasHeader('x-api-key'))->toBeTrue()
        ->and($message->hasHeader('X-API-KEY'))->toBeTrue()
        ->and($message->getHeader('X-Api-Key'))->toBe(['secret123'])
        ->and($message->getHeader('accept'))->toBe(['application/json'])
        ->and($message->getHeader('Non-Existent'))->toBeArray()->toBeEmpty()
    ;
});

it('overwrites headers correctly via setHeader', function () {
    $message = new ConcreteMessage(['X-Test' => 'original']);

    $message->setHeader('X-Test', 'overwritten');
    expect($message->getHeader('x-test'))->toBe(['overwritten']);

    $message->setHeader('X-Test', ['val1', 'val2']);
    expect($message->getHeader('x-test'))->toBe(['val1', 'val2']);
});

it('appends headers correctly via addHeader', function () {
    $message = new ConcreteMessage(['X-Test' => 'value1']);

    $message->addHeader('x-test', 'value2');
    expect($message->getHeader('X-Test'))->toBe(['value1', 'value2']);

    $message->addHeader('X-New', 'new-value');
    expect($message->getHeader('x-new'))->toBe(['new-value']);
});

it('constructs a comma-separated header line correctly', function () {
    $message = new ConcreteMessage([
        'Cache-Control' => ['no-cache', 'no-store', 'must-revalidate'],
    ]);

    expect($message->getHeaderLine('Cache-Control'))->toBe('no-cache, no-store, must-revalidate')
        ->and($message->getHeaderLine('Non-Existent'))->toBe('')
    ;
});
