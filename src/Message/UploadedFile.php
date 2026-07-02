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
        \debug("[UploadedFile] Starting moveTo() to: $destinationPath");

        if ($this->moved) {
            return Promise::rejected(new \RuntimeException('File has already been moved.'));
        }

        if (!file_exists($this->tmpPath)) {
            return Promise::rejected(new \RuntimeException('Temporary file no longer exists.'));
        }

        /** @var Promise<void> */
        return new Promise(function (callable $resolve, callable $reject, callable $onCancel) use ($destinationPath) {
            \debug("[UploadedFile] Inside Promise executor. Opening streams...");

            $source = Stream::readableFile($this->tmpPath);
            $dest = Stream::writableFile($destinationPath);

            $source->on('data', function ($chunk) {
                \debug("[UploadedFile] Read chunk of size: " . \strlen((string)$chunk) . " bytes");
            });


            $dest->on('finish', function () use ($resolve, $source) {
                \debug("[UploadedFile] Destination 'finish' emitted. Resolving promise.");
                $source->close();
                $this->moved = true;
                $this->deleteFile($this->tmpPath);
                $resolve(null);
            });

            $dest->on('error', function (\Throwable $e) use ($reject, $source, $destinationPath) {
                \debug("[UploadedFile] Destination 'error' emitted: " . $e->getMessage());
                $source->close();
                $this->deleteFile($destinationPath);
                $reject($e);
            });

            $source->on('error', function (\Throwable $e) use ($reject, $dest, $destinationPath) {
                \debug("[UploadedFile] Source 'error' emitted: " . $e->getMessage());
                $dest->close();
                $this->deleteFile($destinationPath); 
                $reject($e);
            });

            $onCancel(static function () use ($source, $dest, $destinationPath) {
                \debug("[UploadedFile] onCancel triggered! Closing streams and unlinking destination...");
                $source->close();
                $dest->close();

                if (file_exists($destinationPath)) {
                    \debug("[UploadedFile] Unlinking partial destination file.");
                    unlink($destinationPath);
                } else {
                    \debug("[UploadedFile] No destination file found to unlink during cancel.");
                }
            });

            \debug("[UploadedFile] Piping source to destination...");
            $source->pipe($dest);
        });
    }

    /**
     * Safely deletes a file only if it exists, without relying on error suppression.
     */
    private function deleteFile(string $path): void
    {
        if (file_exists($path)) {
            \debug("[UploadedFile] Deleting file: $path");
            unlink($path);
        }
    }

    public function __destruct()
    {
        if (!$this->moved) {
            $this->deleteFile($this->tmpPath);
        }
    }
}