<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Stream;

final class UploadedFile
{
    private bool $moved = false;

    public function __construct(
        public readonly string $tmpPath,
        public readonly string $clientFilename,
        public readonly string $clientMediaType,
        public readonly int $size
    ) {
    }

    /**
     * Asynchronously moves the uploaded file using standard streams and pure promise events.
     *
     * @return PromiseInterface<void>
     */
    public function moveTo(string $destinationPath): PromiseInterface
    {
        if ($this->moved) {
            return Promise::rejected(new \RuntimeException('File has already been moved.'));
        }

        if (! file_exists($this->tmpPath)) {
            return Promise::rejected(new \RuntimeException('Temporary file no longer exists.'));
        }

        /** @var Promise<void> */
        return new Promise(function (callable $resolve, callable $reject, callable $onCancel) use ($destinationPath) {
            $source = Stream::readableFile($this->tmpPath);
            $dest = Stream::writableFile($destinationPath);

            $dest->on('finish', function () use ($resolve, $source) {
                $source->close();
                $this->moved = true;
                $this->deleteFile($this->tmpPath);
                $resolve(null);
            });

            $dest->on('error', function (\Throwable $e) use ($reject, $source, $destinationPath) {
                $source->close();
                $this->deleteFile($destinationPath);
                $reject($e);
            });

            $source->on('error', function (\Throwable $e) use ($reject, $dest, $destinationPath) {
                $dest->close();
                $this->deleteFile($destinationPath);
                $reject($e);
            });

            $onCancel(static function () use ($source, $dest, $destinationPath) {
                $source->close();
                $dest->close();

                if (file_exists($destinationPath)) {
                    unlink($destinationPath);
                }
            });

            $source->pipe($dest);
        });
    }

    /**
     * Safely deletes a file only if it exists, without relying on error suppression.
     */
    private function deleteFile(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function __destruct()
    {
        if (! $this->moved) {
            $this->deleteFile($this->tmpPath);
        }
    }
}
