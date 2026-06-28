<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Http;
use Hibla\HttpClient\SSE\SSEControl;
use Hibla\HttpClient\SSE\SSEEvent as ClientSseEvent;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\HttpServer\Message\SseStream as ServerSseStream;
use Hibla\Promise\Promise;
use Hibla\Stream\ThroughStream;

use function Hibla\await;

afterEach(function () {
    Loop::reset();
});

describe('Core HTTP Functionality', function () {

    it('handles a real GET request end-to-end', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext("Hello from the Server! Method: {$request->getMethod()}");
        });

        try {
            $response = await(Http::get($url));
            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('Hello from the Server! Method: GET')
            ;
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
                ->and($response->json('received_name'))->toBe('Hibla')
            ;
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
                ->and($receivedData)->toBe("Chunk 1\nChunk 2\n")
            ;
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
                    if (count($receivedEvents) === 2 && $resolveEvents) {
                        $resolveEvents(true);
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
                ->and($receivedEvents[1]->event)->toBe('custom_event')
            ;
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
                $promises[] = Http::client()->withHeader('X-Request-ID', (string) $i)->get($url);
            }

            $responses = await(Promise::all($promises));
            expect($responses)->toHaveCount(100);

            foreach ($responses as $index => $response) {
                expect($response->status())->toBe(200)
                    ->and($response->body())->toBe("OK: {$index}")
                ;
            }
        } finally {
            $socket->close();
        }
    });
});

describe('Browser-Like Simulation', function () {

    it('persists cookies across requests like a real browser', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            if ($request->getUri() === '/login') {
                return new ServerResponse(200, ['Set-Cookie' => 'session_id=xyz123; HttpOnly'], 'Logged in');
            }
            if ($request->getUri() === '/dashboard') {
                $cookie = $request->getHeaderLine('Cookie');

                return ServerResponse::plaintext("Cookie received: {$cookie}");
            }

            return new ServerResponse(404);
        });

        try {
            $client = Http::client()->withCookieJar();

            $loginResponse = await($client->get($url . '/login'));
            expect($loginResponse->status())->toBe(200);

            $dashboardResponse = await($client->get($url . '/dashboard'));
            expect($dashboardResponse->body())->toBe('Cookie received: session_id=xyz123');
        } finally {
            $socket->close();
        }
    });

    it('follows redirects automatically', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            if ($request->getUri() === '/old-page') {
                return new ServerResponse(302, ['Location' => '/new-page']);
            }
            if ($request->getUri() === '/new-page') {
                return ServerResponse::plaintext('You have reached the new page');
            }

            return new ServerResponse(404);
        });

        try {
            $response = await(Http::client()->redirects(follow: true, max: 5)->get($url . '/old-page'));
            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('You have reached the new page')
            ;
        } finally {
            $socket->close();
        }
    });

    it('submits multipart form data including file uploads', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $contentType = $request->getHeaderLine('Content-Type');
            $body = (string) $request->getBody();

            if (str_contains($contentType, 'multipart/form-data') && str_contains($body, 'dummy_file_content')) {
                return ServerResponse::plaintext('Upload successful');
            }

            return new ServerResponse(400);
        });

        $tmpFile = tempnam(sys_get_temp_dir(), 'hibla_test_');
        file_put_contents($tmpFile, 'dummy_file_content');

        try {
            $response = await(Http::client()
                ->multipartWithFiles(data: ['username' => 'test_user'], files: ['avatar' => $tmpFile])
                ->post($url . '/upload'));

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('Upload successful')
            ;
        } finally {
            @unlink($tmpFile);
            $socket->close();
        }
    });

    it('fetches concurrent assets like a browser rendering a page', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $uri = $request->getUri();
            if ($uri === '/index.html') {
                return ServerResponse::html('<link href="/style.css"><script src="/app.js"></script><img src="/logo.png">');
            }
            if ($uri === '/style.css') {
                return new ServerResponse(200, ['Content-Type' => 'text/css'], 'body { color: red; }');
            }
            if ($uri === '/app.js') {
                return new ServerResponse(200, ['Content-Type' => 'application/javascript'], 'console.log("hi");');
            }
            if ($uri === '/logo.png') {
                return new ServerResponse(200, ['Content-Type' => 'image/png'], 'fake_png_bytes');
            }

            return new ServerResponse(404);
        });

        try {
            $htmlResponse = await(Http::get($url . '/index.html'));
            expect($htmlResponse->status())->toBe(200);

            $assets = ['/style.css', '/app.js', '/logo.png'];
            $promises = array_map(fn($asset) => Http::get($url . $asset), $assets);

            $responses = await(Promise::all($promises));

            expect($responses)->toHaveCount(3)
                ->and($responses[0]->header('content-type'))->toBe('text/css')
                ->and($responses[1]->header('content-type'))->toBe('application/javascript')
                ->and($responses[2]->header('content-type'))->toBe('image/png')
            ;
        } finally {
            $socket->close();
        }
    });
});

describe('Advanced Client-Server Interactions', function () {

    it('streams massive request bodies asynchronously without buffering in memory', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $stream = $request->getBody();
            $receivedBytes = 0;

            $uploadPromise = new Promise(function ($resolve) use ($stream, &$receivedBytes) {
                $stream->on('data', function (string $chunk) use (&$receivedBytes) {
                    $receivedBytes += strlen($chunk);
                });

                $stream->on('end', function () use (&$receivedBytes, $resolve) {
                    $resolve($receivedBytes);
                });
            });

            $totalBytes = await($uploadPromise);

            return ServerResponse::plaintext("Fully streamed {$totalBytes} bytes");
        }, maxBodySize: 10485760, streamingRequests: true);

        try {
            $payload = str_repeat('X', 1024 * 1024);

            $response = await(Http::post($url, ['data' => $payload]));

            expect($response->status())->toBe(200)
                ->and($response->body())->toContain('Fully streamed')
            ;
        } finally {
            $socket->close();
        }
    });

    it('accurately preserves and routes complex query parameters', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext("URI: {$request->getUri()}");
        });

        try {
            $response = await(Http::get($url . '/api/search', [
                'q' => 'hibla async',
                'filters' => ['active' => 'true', 'id' => 42],
            ]));

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('URI: /api/search?q=hibla+async&filters%5Bactive%5D=true&filters%5Bid%5D=42')
            ;
        } finally {
            $socket->close();
        }
    });

    it('handles custom request and response headers seamlessly', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $clientAuth = $request->getHeaderLine('X-Client-Auth');

            if ($clientAuth !== 'super-secret') {
                return new ServerResponse(401, [], 'Unauthorized');
            }

            return new ServerResponse(200, [
                'X-Server-Ack' => 'authenticated',
                'X-RateLimit-Remaining' => '99',
            ], 'Welcome');
        });

        try {
            $client = Http::client()->withHeader('X-Client-Auth', 'super-secret');

            $response = await($client->get($url));

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('Welcome')
                ->and($response->header('x-server-ack'))->toBe('authenticated')
                ->and($response->header('x-ratelimit-remaining'))->toBe('99')
            ;

            $badResponse = await(Http::client()->get($url));
            expect($badResponse->status())->toBe(401);
        } finally {
            $socket->close();
        }
    });

    it('correctly reports HTTP 404 Not Found and custom status codes', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            if ($request->getUri() === '/teapot') {
                return new ServerResponse(418, [], 'Short and stout');
            }

            return new ServerResponse(404, [], 'Page not found');
        });

        try {
            $notFound = await(Http::get($url . '/missing'));
            expect($notFound->status())->toBe(404)
                ->and($notFound->body())->toBe('Page not found')
            ;

            $teapot = await(Http::get($url . '/teapot'));
            expect($teapot->status())->toBe(418)
                ->and($teapot->body())->toBe('Short and stout')
            ;
        } finally {
            $socket->close();
        }
    });
});
