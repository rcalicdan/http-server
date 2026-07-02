<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

final class MultipartForm
{
    /**
     * @var array<string, string>
     */
    private array $fields = [];

    /**
     * @var array<string, UploadedFile|list<UploadedFile>>
     */
    private array $files = [];

    public function addField(string $name, string $value): void
    {
        $this->fields[$name] = $value;
    }

    public function addFile(string $name, UploadedFile $file): void
    {
        if (str_ends_with($name, '[]')) {
            $cleanName = substr($name, 0, -2);

            if (! isset($this->files[$cleanName]) || ! \is_array($this->files[$cleanName])) {
                $this->files[$cleanName] = [];
            }

            $this->files[$cleanName][] = $file;
        } else {
            $this->files[$name] = $file;
        }
    }

    public function get(string $name): ?string
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * @return UploadedFile|list<UploadedFile>|null
     */
    public function getFile(string $name): UploadedFile|array|null
    {
        return $this->files[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->fields;
    }
}
