<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Evenement\EventEmitter;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\ThroughStream;

/**
 * High-performance, streaming multipart/form-data parser.
 * Operates purely in-memory with a bounded sliding window.
 */
class MultipartParser extends EventEmitter implements WritableStreamInterface
{
    private const int STATE_PREAMBLE = 0;

    private const int STATE_BOUNDARY_SUFFIX = 1;

    private const int STATE_HEADERS = 2;

    private const int STATE_BODY = 3;

    private const int STATE_EPILOGUE = 4;

    private int $state = self::STATE_PREAMBLE;

    private string $buffer = '';

    private string $boundary;

    private bool $writable = true;

    private ?string $currentName = null;

    private ?string $currentFilename = null;

    private ?string $currentMime = null;

    private ?string $currentFieldBuffer = null;

    private ?ThroughStream $currentFileStream = null;

    public function __construct(string $boundary)
    {
        $this->boundary = $boundary;
    }

    public function write(string $data): bool
    {
        if (! $this->writable) {
            return false;
        }

        $this->buffer .= $data;
        $this->parseBuffer();

        return true;
    }

    private function parseBuffer(): void
    {
        while (\strlen($this->buffer) > 0) {

            if ($this->state === self::STATE_PREAMBLE) {
                $boundary = '--' . $this->boundary;
                $pos = strpos($this->buffer, $boundary);

                if ($pos !== false) {
                    $this->buffer = substr($this->buffer, $pos + \strlen($boundary));
                    $this->state = self::STATE_BOUNDARY_SUFFIX;
                } else {
                    // Safe to discard everything except the last N bytes where a boundary might be forming
                    $keep = \strlen($boundary);
                    if (\strlen($this->buffer) > $keep) {
                        $this->buffer = substr($this->buffer, -$keep);
                    }

                    return;
                }
            } elseif ($this->state === self::STATE_BOUNDARY_SUFFIX) {
                if (\strlen($this->buffer) < 2) {
                    return;
                } // Wait for \r\n or --

                $suffix = substr($this->buffer, 0, 2);
                $this->buffer = substr($this->buffer, 2);

                if ($suffix === '--') {
                    $this->state = self::STATE_EPILOGUE;
                    $this->emit('end');

                    return;
                } elseif ($suffix === "\r\n") {
                    $this->state = self::STATE_HEADERS;
                } else {
                    // Malformed, but attempt recovery
                    $this->state = self::STATE_HEADERS;
                }
            } elseif ($this->state === self::STATE_HEADERS) {
                $pos = strpos($this->buffer, "\r\n\r\n");

                if ($pos !== false) {
                    $rawHeaders = substr($this->buffer, 0, $pos);
                    $this->buffer = substr($this->buffer, $pos + 4);
                    $this->processHeaders($rawHeaders);
                    $this->state = self::STATE_BODY;
                } else {
                    // Prevent memory exhaustion from malicious header blocks
                    if (\strlen($this->buffer) > 8192) {
                        $this->emit('error', [new \RuntimeException('Multipart headers too large')]);
                        $this->close();
                    }

                    return;
                }
            } elseif ($this->state === self::STATE_BODY) {
                $marker = "\r\n--" . $this->boundary;
                $pos = strpos($this->buffer, $marker);

                if ($pos !== false) {
                    // Complete boundary found!
                    $chunk = substr($this->buffer, 0, $pos);
                    if ($chunk !== '') {
                        $this->emitChunk($chunk);
                    }
                    $this->finishCurrentPart();

                    $this->buffer = substr($this->buffer, $pos + \strlen($marker));
                    $this->state = self::STATE_BOUNDARY_SUFFIX;
                } else {
                    // No complete boundary found. Emit data up to the safe margin.
                    $markerLen = \strlen($marker);
                    $bufferLen = \strlen($this->buffer);

                    if ($bufferLen > $markerLen) {
                        $safeLen = $bufferLen - $markerLen;
                        $chunk = substr($this->buffer, 0, $safeLen);
                        $this->buffer = substr($this->buffer, $safeLen);
                        $this->emitChunk($chunk);
                    }

                    return; // Wait for more TCP packets
                }
            } elseif ($this->state === self::STATE_EPILOGUE) {
                $this->buffer = '';

                return;
            }
        }
    }

    private function processHeaders(string $rawHeaders): void
    {
        if (preg_match('/name="([^"]+)"/i', $rawHeaders, $m) === 1) {
            $this->currentName = $m[1];
        }
        if (preg_match('/filename="([^"]+)"/i', $rawHeaders, $m) === 1) {
            $this->currentFilename = $m[1];
        }
        if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $rawHeaders, $m) === 1) {
            $this->currentMime = trim($m[1]);
        }

        if ($this->currentFilename !== null) {
            $this->currentFileStream = new ThroughStream();
            $this->emit('file', [$this->currentName, $this->currentFilename, $this->currentMime, $this->currentFileStream]);
        } else {
            $this->currentFieldBuffer = '';
        }
    }

    private function emitChunk(string $chunk): void
    {
        if ($this->currentFileStream !== null) {
            $this->currentFileStream->write($chunk);
        } elseif ($this->currentFieldBuffer !== null) {
            $this->currentFieldBuffer .= $chunk;
        }
    }

    private function finishCurrentPart(): void
    {
        if ($this->currentFileStream !== null) {
            $this->currentFileStream->end();
            $this->currentFileStream = null;
        } elseif ($this->currentFieldBuffer !== null) {
            $this->emit('field', [$this->currentName, $this->currentFieldBuffer]);
            $this->currentFieldBuffer = null;
        }

        $this->currentName = null;
        $this->currentFilename = null;
        $this->currentMime = null;
    }

    public function end(?string $data = null): void
    {
        if ($data !== null && $data !== '') {
            $this->write($data);
        }

        $this->writable = false;

        if ($this->currentFileStream !== null) {
            $this->currentFileStream->end();
        }

        $this->emit('close');
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function close(): void
    {
        $this->writable = false;
    }
}
