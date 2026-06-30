<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Internals;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Exceptions\InvalidResponseException;
use Hibla\HttpServer\Interfaces\ConnectionManagerInterface;
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;
use Hibla\Socket\Interfaces\ConnectionInterface;

/**
 * @internal
 */
final class Http11ConnectionManager implements ConnectionManagerInterface
{
    /**
     * @var list<Http11PipelineItem>
     */
    private array $pipelineQueue = [];

    private bool $isFlushing = false;

    private int $maxPipelineDepth = 10;

    private ?Http11ProtocolHandler $protocolHandler = null;

    /**
     * @var callable(Request, ProtocolHandlerInterface): Response
     */
    private $requestHandler;

    /**
     * @param callable(Request, ProtocolHandlerInterface): Response $requestHandler
     */
    public function __construct(
        callable $requestHandler,
        private readonly int $maxBodySize = 10485760,
        private readonly bool $streamingRequests = false,
        private readonly int $maxHeaderSize = 8192,
        private readonly int $maxHeaderCount = 100,
        private readonly ?float $headerTimeout = null,
        private readonly ?float $keepAliveTimeout = null
    ) {
        $this->requestHandler = $requestHandler;
    }

    public function handle(ConnectionInterface $connection): void
    {
        $this->protocolHandler = new Http11ProtocolHandler(
            $connection,
            $this->onRequest(...),
            $this->maxBodySize,
            $this->streamingRequests,
            $this->maxHeaderSize,
            $this->maxHeaderCount,
            $this->headerTimeout,
            $this->keepAliveTimeout
        );

        $this->protocolHandler->onEarlyResponse = function (string $data) use ($connection): void {
            $item = new Http11PipelineItem();
            $item->isEarly = true;
            $item->data = $data;
            $item->isReady = true;

            $this->pipelineQueue[] = $item;

            if (\count($this->pipelineQueue) >= $this->maxPipelineDepth) {
                $connection->pause();
            }

            $this->flushQueue();
        };

        $connection->on('close', function (): void {
            $this->pipelineQueue = [];
            $this->protocolHandler = null;
        });

        $connection->on('data', function (string $data): void {
            $this->protocolHandler?->handleData($data);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function gracefulShutdown(): void
    {
        $this->protocolHandler?->gracefulShutdown();
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveRequestsCount(): int
    {
        return $this->protocolHandler?->getActiveRequestsCount() ?? 0;
    }

    /**
     * {@inheritDoc}
     */
    public function isUpgraded(): bool
    {
        return $this->protocolHandler?->isUpgraded() ?? true;
    }

    private function onRequest(Request $request, ProtocolHandlerInterface $protocol): void
    {
        $item = new Http11PipelineItem();
        $this->pipelineQueue[] = $item;

        if (\count($this->pipelineQueue) >= $this->maxPipelineDepth) {
            $protocol->getConnection()->pause();
        }

        $fiber = new \Fiber(function () use ($request, $protocol, $item): void {
            try {
                $response = ($this->requestHandler)($request, $protocol);

                if ($protocol->isUpgraded()) {
                    $item->response = null;
                } elseif (! $response instanceof Response) {
                    throw new InvalidResponseException('Request handler must return an instance of Response');
                } else {
                    $item->response = $response;
                }
            } catch (\Throwable $e) {
                if (! $protocol->isUpgraded()) {
                    $item->response = Response::plaintext("500 Internal Server Error\n" . $e->getMessage(), 500);
                } else {
                    $item->response = null;
                }
            } finally {
                $item->isReady = true;
                $this->flushQueue();
            }
        });

        Loop::addFiber($fiber);
    }

    private function flushQueue(): void
    {
        if ($this->isFlushing || $this->pipelineQueue === [] || $this->protocolHandler === null) {
            return;
        }

        $head = $this->pipelineQueue[0];
        if (! $head->isReady) {
            return;
        }

        $this->isFlushing = true;
        $connection = $this->protocolHandler->getConnection();

        $onComplete = function () use ($connection): void {
            array_shift($this->pipelineQueue);
            $this->isFlushing = false;

            if (\count($this->pipelineQueue) < $this->maxPipelineDepth) {
                $connection->resume();
            }

            $this->flushQueue();
        };

        if ($head->isEarly) {
            if (\is_string($head->data)) {
                $connection->write($head->data);
            }
            $onComplete();
        } else {
            if ($head->response instanceof Response && ! $this->protocolHandler->isUpgraded()) {
                try {
                    $this->protocolHandler->writeResponse($head->response, $onComplete);
                } catch (\Throwable $e) {
                    try {
                        $errorResponse = Response::plaintext("500 Internal Server Error\n" . $e->getMessage(), 500);
                        $this->protocolHandler->writeResponse($errorResponse, $onComplete);
                    } catch (\Throwable) {
                        $connection->close();
                        $onComplete();
                    }
                }
            } else {
                $this->protocolHandler->decrementActiveRequests();
                $onComplete();
            }
        }
    }
}
