<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Stream;

/**
 * Concrete implementation of an incoming HTTP Request Value Object.
 */
final class Request extends AbstractMessage
{
    /**
     * @param string $method
     * @param string $uri
     * @param array<string, string|list<string>> $headers
     * @param string|ReadableStreamInterface $body
     * @param string $protocolVersion
     * @param array<string, mixed> $serverParams
     */
    public function __construct(
        public string $method,
        public string $uri,
        array $headers = [],
        string|ReadableStreamInterface $body = '',
        string $protocolVersion = '1.1',
        public array $serverParams = []
    ) {
        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $body;
        $this->protocolVersion = $protocolVersion;
    }

    /**
     * Retrieves the HTTP method of the request (e.g., "GET", "POST").
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Retrieves the request URI or path.
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Retrieves server-side environment parameters.
     *
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * Parses the request body as multipart/form-data.
     * Operates purely via event-driven stream pipes and promise chaining.
     *
     * @return PromiseInterface<MultipartForm>
     */
    public function getParsedBody(): PromiseInterface
    {
        $contentType = $this->getHeaderLine('Content-Type');

        if (preg_match('/boundary=(?:"([^"]+)"|([^;,\s]+))/i', $contentType, $matches) !== 1) {
            return Promise::rejected(new \RuntimeException('Not a valid multipart/form-data request'));
        }

        $boundary = $matches[1] !== '' ? $matches[1] : $matches[2];

        /** @var Promise<MultipartForm> */
        return new Promise(function (callable $resolve, callable $reject, callable $onCancel) use ($boundary) {
            $parser = new MultipartParser($boundary);
            $form = new MultipartForm();

            /** @var array<int, PromiseInterface<null>> $writePromises */
            $writePromises = [];

            $parser->on('field', function (mixed $name, mixed $value) use ($form) {
                if (\is_string($name) && \is_string($value)) {
                    $form->addField($name, $value);
                }
            });

            $parser->on('file', function (mixed $name, mixed $filename, mixed $mime, mixed $fileStream) use ($form, &$writePromises) {
                if (! \is_string($name) || ! \is_string($filename) || ! \is_string($mime) || ! $fileStream instanceof ReadableStreamInterface) {
                    return;
                }

                $tmpPath = tempnam(sys_get_temp_dir(), 'hibla_up_');
                if ($tmpPath === false) {
                    return;
                }
                
                $destination = Stream::writableFile($tmpPath);

                /** @var Promise<null> $writePromise */
                $writePromise = new Promise(function ($res, $rej, $onFileCancel) use ($fileStream, $destination, $tmpPath, $form, $name, $filename, $mime) {
                    $bytesWritten = 0;

                    $fileStream->on('data', function (mixed $chunk) use (&$bytesWritten) {
                        if (\is_string($chunk)) {
                            $bytesWritten += \strlen($chunk);
                        }
                    });

                    $destination->on('finish', function () use ($res, $form, $name, $filename, $mime, $tmpPath, &$bytesWritten) {
                        $form->addFile($name, new UploadedFile($tmpPath, $filename, $mime, $bytesWritten));
                        $res(null);
                    });

                    $destination->on('error', function (mixed $e) use ($rej, $tmpPath) {
                        @unlink($tmpPath);
                        $rej($e instanceof \Throwable ? $e : new \RuntimeException('Write error'));
                    });

                    $fileStream->on('error', function (mixed $e) use ($rej, $destination, $tmpPath) {
                        $destination->close();
                        @unlink($tmpPath);
                        $rej($e instanceof \Throwable ? $e : new \RuntimeException('Stream error'));
                    });

                    $onFileCancel(static function () use ($fileStream, $destination, $tmpPath) {
                        $fileStream->close();
                        $destination->close();
                        @unlink($tmpPath);
                    });

                    $fileStream->pipe($destination);
                });

                $writePromises[] = $writePromise;
            });

            $body = $this->getBody();
            $isStream = $body instanceof ReadableStreamInterface;

            /** @var Promise<null> $parserPromise */
            $parserPromise = new Promise(function (callable $res, callable $rej) use ($parser, $isStream, $body) {
                $parser->on('end', static function () use ($res) {
                    $res(null);
                });

                $parser->on('error', static function (mixed $e) use ($rej) {
                    $rej($e instanceof \Throwable ? $e : new \RuntimeException('Parser error'));
                });

                if ($isStream && $body instanceof ReadableStreamInterface) {
                    $body->on('error', static function (mixed $e) use ($rej) {
                        $rej($e instanceof \Throwable ? $e : new \RuntimeException('Body stream error'));
                    });
                }
            });

            $onCancel(static function () use (&$writePromises, $parserPromise, $isStream, $body, $parser) {
                foreach ($writePromises as $p) {
                    $p->cancel();
                }

                $parserPromise->cancel();
                $parser->close();

                if ($isStream && $body instanceof ReadableStreamInterface) {
                    $body->close();
                }
            });

            $parserPromise->then(static function () use (&$writePromises) {
                if (\count($writePromises) === 0) {
                    /** @var PromiseInterface<null> $nullPromise */
                    $nullPromise = Promise::resolved(null);
                    return $nullPromise;
                }

                /** @var PromiseInterface<array<int|string, null>> $allPromise */
                $allPromise = Promise::all($writePromises);
                return $allPromise;
            })->then(static function () use ($resolve, $form) {
                $resolve($form);
            })->catch(static function (\Throwable $error) use ($reject) {
                $reject($error);
            });

            if ($isStream && $body instanceof ReadableStreamInterface) {
                $body->pipe($parser);
            } elseif (\is_string($body)) {
                $parser->write($body);
                $parser->end();
            }
        });
    }
}