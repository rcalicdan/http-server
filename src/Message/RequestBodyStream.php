<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Evenement\EventEmitter;
use Hibla\EventLoop\Loop;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Util;

class RequestBodyStream extends EventEmitter implements ReadableStreamInterface
{
    private bool $readable = true;

    private bool $paused = false;

    /**
     * Buffers data that arrives before the application attaches a data listener.
     */
    private string $buffer = '';

    private bool $hasDataListener = false;

    private bool $ended = false;

    /**
     * @inheritDoc
     */
    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * @inheritDoc
     */
    public function pause(): void
    {
        if ($this->paused) {
            return;
        }

        $this->paused = true;
        $this->emit('pause');
    }

    /**
     * @inheritDoc
     */
    public function resume(): void
    {
        if (! $this->paused) {
            return;
        }

        $this->paused = false;
        $this->emit('resume');
    }

    /**
     * @inheritDoc
     */
    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if (! $this->readable) {
            return;
        }

        $this->readable = false;
        $this->emit('close');
        $this->removeAllListeners();
    }

    /**
     * {@inheritDoc}
     *
     * Overridden to detect when the application starts listening for data.
     * If data arrived before the listener was attached (e.g. in the same TCP tick as the headers),
     * it is flushed out in the next tick to ensure the listener doesn't miss it.
     *
     * @param mixed $event
     */
    public function on($event, callable $listener): void
    {
        parent::on($event, $listener);

        if ($event === 'data' && ! $this->hasDataListener) {
            $this->hasDataListener = true;

            if ($this->buffer !== '' || $this->ended) {
                Loop::nextTick(function () {
                    if (! $this->readable) {
                        return;
                    }

                    if ($this->buffer !== '') {
                        $data = $this->buffer;
                        $this->buffer = '';
                        $this->emit('data', [$data]);
                    }

                    // PHPStan believes $this->readable is always true here, but $this->emit()
                    // is impure and can trigger a close() callback, changing the state.
                    // @phpstan-ignore booleanAnd.rightAlwaysTrue
                    if ($this->ended && $this->readable) {
                        $this->emit('end');
                        $this->close();
                    }
                });
            }
        }
    }

    /**
     * @internal Called by the Protocol Handler to push bytes into the stream
     */
    public function push(string $data): void
    {
        if (! $this->readable || $data === '') {
            return;
        }

        if ($this->hasDataListener) {
            $this->emit('data', [$data]);
        } else {
            $this->buffer .= $data;
        }
    }

    /**
     * @internal Called by the Protocol Handler when the body is fully received
     */
    public function end(): void
    {
        if (! $this->readable) {
            return;
        }

        if ($this->buffer === '') {
            $this->emit('end');
            $this->close();
        } else {
            $this->ended = true;
        }
    }
}
