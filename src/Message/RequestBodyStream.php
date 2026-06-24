<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Evenement\EventEmitter;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Util;

class RequestBodyStream extends EventEmitter implements ReadableStreamInterface
{
    private bool $readable = true;

    private bool $paused = false;

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function pause(): void
    {
        if ($this->paused) {
            return;
        }

        $this->paused = true;
        $this->emit('pause');
    }

    public function resume(): void
    {
        if (! $this->paused) {
            return;
        }

        $this->paused = false;
        $this->emit('resume');
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
    }

    /**
     * @internal Called by the Protocol Handler to push bytes into the stream
     */
    public function push(string $data): void
    {
        if ($this->readable && $data !== '') {
            $this->emit('data', [$data]);
        }
    }

    /**
     * @internal Called by the Protocol Handler when the body is fully received
     */
    public function end(): void
    {
        if ($this->readable) {
            $this->emit('end');
            $this->close();
        }
    }
}
