<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Message\SseStream;

it('starts as readable and unpaused', function () {
    $stream = new SseStream();

    expect($stream->isReadable())->toBeTrue();
});

it('formats a basic data event correctly', function () {
    $stream = new SseStream();
    $emittedPayload = '';

    $stream->on('data', function (string $payload) use (&$emittedPayload) {
        $emittedPayload = $payload;
    });

    $stream->send('hello world');

    expect($emittedPayload)->toBe("data: hello world\n\n");
});

it('formats events with custom IDs, events, and retry timeouts', function () {
    $stream = new SseStream();
    $emittedPayload = '';

    $stream->on('data', function (string $payload) use (&$emittedPayload) {
        $emittedPayload = $payload;
    });

    $stream->send(
        data: 'user logged in',
        event: 'login',
        id: 'msg-101',
        retry: 5000
    );

    $expected = "id: msg-101\n" .
                "event: login\n" .
                "retry: 5000\n" .
                "data: user logged in\n\n";

    expect($emittedPayload)->toBe($expected);
});

it('splits multiline data into multiple data prefix lines', function () {
    $stream = new SseStream();
    $emittedPayload = '';

    $stream->on('data', function (string $payload) use (&$emittedPayload) {
        $emittedPayload = $payload;
    });

    $stream->send("Line One\nLine Two\nLine Three");

    $expected = "data: Line One\n" .
                "data: Line Two\n" .
                "data: Line Three\n\n";

    expect($emittedPayload)->toBe($expected);
});

it('does not emit events once closed', function () {
    $stream = new SseStream();
    $emittedCount = 0;

    $stream->on('data', function () use (&$emittedCount) {
        $emittedCount++;
    });

    $stream->send('First Event');
    $stream->close();

    $stream->send('Second Event');

    expect($emittedCount)->toBe(1);
});

it('suspends the emitter fiber when the stream is paused to apply backpressure', function () {
    $stream = new SseStream();
    $stepReached = 0;

    $fiber = new Fiber(function () use ($stream, &$stepReached) {
        $stepReached = 1;
        $stream->send('First Event');

        $stepReached = 2;
        $stream->send('Second Event');

        $stepReached = 3;
    });

    $stream->setEmitterFiber($fiber);

    $stream->pause();

    $fiber->start();

    expect($fiber->isSuspended())->toBeTrue()
        ->and($stepReached)->toBe(1)
    ;

    $stream->resume();
    $fiber->resume();

    expect($fiber->isTerminated())->toBeTrue()
        ->and($stepReached)->toBe(3)
    ;

    $stream->close();
});

it('integrates with the real Event Loop to schedule and resume suspended fibers automatically', function () {
    $stream = new SseStream();
    $stepReached = 0;

    $fiber = new Fiber(function () use ($stream, &$stepReached) {
        $stepReached = 1;
        $stream->send('First Event');

        $stepReached = 2;
        $stream->send('Second Event');

        $stepReached = 3;
    });

    $stream->setEmitterFiber($fiber);

    $stream->pause();

    Loop::addFiber($fiber);

    Loop::nextTick(function () use ($stream) {
        $stream->resume(); // This internally calls Loop::scheduleFiber()
    });

    Loop::run();

    expect($fiber->isTerminated())->toBeTrue()
        ->and($stepReached)->toBe(3)
    ;

    $stream->close();
});
