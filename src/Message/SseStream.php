<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Evenement\EventEmitter;
use Hibla\EventLoop\Loop;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Util;

class SseStream extends EventEmitter implements ReadableStreamInterface
{
    private bool $readable = true;

    private bool $paused = false;

    /**
     * @var \Fiber<mixed, mixed, mixed, mixed>|null The working fiber driving this stream
     */
    private ?\Fiber $emitterFiber = null;

    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * Called by the ProtocolHandler/Socket when the TCP buffer is full.
     */
    public function pause(): void
    {
        $this->paused = true;
    }

    /**
     * Called by the ProtocolHandler/Socket when the TCP buffer has drained.
     */
    public function resume(): void
    {
        $this->paused = false;

        if ($this->emitterFiber !== null && $this->emitterFiber->isSuspended()) {
            Loop::scheduleFiber($this->emitterFiber);
        }
    }

    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }

    public function close(): void
    {
        if (! $this->readable) {
            return;
        }
        $this->readable = false;
        $this->emit('close');
        $this->removeAllListeners();

        if ($this->emitterFiber !== null && $this->emitterFiber->isSuspended()) {
            Loop::scheduleFiber($this->emitterFiber);
        }
    }

    public function end(): void
    {
        if (! $this->readable) {
            return;
        }
        $this->emit('end');
        $this->close();
    }

    /**
     * Safely formats and pushes an SSE message to the client.
     * Applies backpressure by suspending the fiber if the stream is paused.
     */
    public function send(string $data, ?string $event = null, ?string $id = null, ?int $retry = null): void
    {
        if ($this->paused && $this->emitterFiber !== null && \Fiber::getCurrent() === $this->emitterFiber) {
            \Fiber::suspend();
        }

        if (! $this->readable) {
            return;
        }

        $payload = '';
        if ($id !== null) {
            $payload .= "id: {$id}\n";
        }
        if ($event !== null) {
            $payload .= "event: {$event}\n";
        }
        if ($retry !== null) {
            $payload .= "retry: {$retry}\n";
        }

        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $payload .= "data: {$line}\n";
        }
        $payload .= "\n";

        $this->emit('data', [$payload]);
    }

    /**
     * @internal Used by Response::sse() to register the working fiber.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    public function setEmitterFiber(\Fiber $fiber): void
    {
        $this->emitterFiber = $fiber;
    }
}
