<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Http;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\SSE\SSEControl;
use Hibla\HttpClient\SSE\SSEEvent as ClientSseEvent;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\HttpServer\Message\SseStream as ServerSseStream;
use Hibla\Promise\Promise;
use Hibla\Stream\ThroughStream;

use function Hibla\await;

describe('End-to-End Functional Tests (Real Sockets)', function () {

    it('handles a real GET request end-to-end', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext("Hello from the Server! Method: {$request->getMethod()}");
        });

        try {
            $response = await(Http::get($url));

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('Hello from the Server! Method: GET');
        } finally {
            $socket->close();
        }
    });

    it('handles a real POST request with a JSON payload', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $data = json_decode((string) $request->getBody(), true);
            return ServerResponse::json(['received_name' => $data['name']]);
        });

        try {
            $response = await(Http::post($url, ['name' => 'Hibla']));

            expect($response->status())->toBe(200)
                ->and($response->json('received_name'))->toBe('Hibla');
        } finally {
            $socket->close();
        }
    });

    it('handles streaming responses using chunked transfer encoding', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $stream = new ThroughStream();
            
            Loop::addTimer(0.01, function () use ($stream) {
                $stream->write("Chunk 1\n");
                $stream->write("Chunk 2\n");
                $stream->end();
            });

            return new ServerResponse(200, [], $stream);
        });

        try {
            $receivedData = '';
            $response = await(Http::stream($url, function (string $chunk) use (&$receivedData) {
                $receivedData .= $chunk;
            }));

            await($response->readAllAsync());

            expect($response->status())->toBe(200)
                ->and($response->header('transfer-encoding'))->toBe('chunked')
                ->and($receivedData)->toBe("Chunk 1\nChunk 2\n");
        } finally {
            $socket->close();
        }
    });

    it('transmits Server-Sent Events from server to client seamlessly', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::sse(function (ServerSseStream $stream) {
                $stream->send(data: 'event 1', id: '101');
                $stream->send(data: 'event 2', event: 'custom_event');
            });
        });

        try {
            $receivedEvents = [];
            $resolveEvents = null;
            
            $eventsCollected = new Promise(function ($resolve) use (&$resolveEvents) {
                $resolveEvents = $resolve;
            });

            $promise = Http::sse($url)
                ->withoutReconnection()
                ->onEvent(function (ClientSseEvent $event, SSEControl $control) use (&$receivedEvents, &$resolveEvents) {
                    $receivedEvents[] = $event;
                    
                    if (count($receivedEvents) === 2) {
                        if ($resolveEvents) {
                            $resolveEvents(true); 
                        }
                    }
                })
                ->connect();

            $connection = await($promise); 
            await($eventsCollected); 

            $connection->close();

            expect($receivedEvents)->toHaveCount(2)
                ->and($receivedEvents[0]->data)->toBe('event 1')
                ->and($receivedEvents[0]->id)->toBe('101')
                ->and($receivedEvents[1]->data)->toBe('event 2')
                ->and($receivedEvents[1]->event)->toBe('custom_event');
        } finally {
            $socket->close();
        }
    });

    it('handles massive concurrency without dropping connections', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $id = $request->getHeaderLine('X-Request-ID');
            return ServerResponse::plaintext("OK: {$id}");
        });

        try {
            $promises = [];
            for ($i = 0; $i < 100; $i++) {
                $promises[] = Http::client()
                    ->withHeader('X-Request-ID', (string) $i)
                    ->get($url);
            }

            $responses = await(Promise::all($promises));

            expect($responses)->toHaveCount(100);

            foreach ($responses as $index => $response) {
                expect($response->status())->toBe(200)
                    ->and($response->body())->toBe("OK: {$index}");
            }
        } finally {
            $socket->close();
        }
    });

    it('rejects real requests over TCP that exceed the maxHeaderCount limit with a 431', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        }, maxHeaderCount: 5); 

        try {
            $client = Http::client();
            
            for ($i = 0; $i < 10; $i++) {
                $client = $client->withHeader("X-Custom-{$i}", "Value");
            }

            try {
                $response = await($client->get($url));
                expect($response->status())->toBe(431);
            } catch (NetworkException $e) {
                expect($e->getMessage())->toContain('Empty reply from server');
            }
        } finally {
            $socket->close();
        }
    });

});