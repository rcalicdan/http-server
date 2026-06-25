<?php

declare(strict_types=1);

use Evenement\EventEmitter;
use Hibla\HttpServer\Message\RequestBodyStream;
use Hibla\Stream\Interfaces\WritableStreamInterface;

it('starts in a readable and unpaused state', function () {
    $stream = new RequestBodyStream();

    expect($stream->isReadable())->toBeTrue();
});

it('emits data events when pushed', function () {
    $stream = new RequestBodyStream();
    $emittedData = '';

    $stream->on('data', function (string $chunk) use (&$emittedData) {
        $emittedData .= $chunk;
    });

    $stream->push('hello');
    $stream->push(' ');
    $stream->push('world');

    expect($emittedData)->toBe('hello world');
});

it('does not emit empty data strings', function () {
    $stream = new RequestBodyStream();
    $emittedCount = 0;

    $stream->on('data', function () use (&$emittedCount) {
        $emittedCount++;
    });

    $stream->push('');
    $stream->push('data');

    expect($emittedCount)->toBe(1);
});

it('emits pause and resume events correctly', function () {
    $stream = new RequestBodyStream();
    $pauseTriggered = false;
    $resumeTriggered = false;

    $stream->on('pause', function () use (&$pauseTriggered) {
        $pauseTriggered = true;
    });

    $stream->on('resume', function () use (&$resumeTriggered) {
        $resumeTriggered = true;
    });

    $stream->pause();
    $stream->resume();

    expect($pauseTriggered)->toBeTrue()
        ->and($resumeTriggered)->toBeTrue()
    ;
});

it('stops emitting data and closes the stream once closed', function () {
    $stream = new RequestBodyStream();
    $emittedData = '';
    $closeEmitted = false;

    $stream->on('data', function (string $chunk) use (&$emittedData) {
        $emittedData .= $chunk;
    });

    $stream->on('close', function () use (&$closeEmitted) {
        $closeEmitted = true;
    });

    $stream->push('before_close');
    $stream->close();

    expect($stream->isReadable())->toBeFalse()
        ->and($closeEmitted)->toBeTrue()
    ;

    $stream->push('after_close');

    expect($emittedData)->toBe('before_close');
});

it('emits end and close events on end() call', function () {
    $stream = new RequestBodyStream();
    $ended = false;
    $closed = false;

    $stream->on('end', function () use (&$ended) {
        $ended = true;
    });

    $stream->on('close', function () use (&$closed) {
        $closed = true;
    });

    $stream->end();

    expect($ended)->toBeTrue()
        ->and($closed)->toBeTrue()
        ->and($stream->isReadable())->toBeFalse()
    ;
});

it('pipes pushed data to a real WritableStreamInterface', function () {
    $readable = new RequestBodyStream();

    $writable = new class () extends EventEmitter implements WritableStreamInterface {
        public string $receivedBuffer = '';

        public bool $writable = true;

        public function isWritable(): bool
        {
            return $this->writable;
        }

        public function write(string $data): bool
        {
            $this->receivedBuffer .= $data;

            return true;
        }

        public function end(?string $data = null): void
        {
            if ($data !== null) {
                $this->write($data);
            }
            $this->writable = false;
            $this->emit('close');
        }

        public function close(): void
        {
            $this->writable = false;
        }
    };

    $readable->pipe($writable);
    $readable->push('Chunk 1 ');
    $readable->push('Chunk 2');
    $readable->end();
    expect($writable->receivedBuffer)->toBe('Chunk 1 Chunk 2')
        ->and($writable->isWritable())->toBeFalse();
});
