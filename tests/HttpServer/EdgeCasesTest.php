<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Http;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\Promise\Promise;
use Hibla\Socket\Connector;
use Hibla\Stream\ThroughStream;

use function Hibla\await;
use function Hibla\delay;

afterEach(function () {
    Loop::reset();
});

describe('Protocol Edge Cases', function () {
    it('seamlessly handles the Expect: 100-continue handshake', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Body size: ' . strlen((string) $request->getBody()));
        });

        try {
            $payload = str_repeat('X', 50000);

            $response = await(
                Http::client()
                    ->withHeader('Expect', '100-continue')
                    ->body($payload)
                    ->post($url)
            );

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('Body size: 50000')
            ;
        } finally {
            $socket->close();
        }
    });

    it('processes diverse standard and custom HTTP methods safely', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext("Method used: {$request->getMethod()}");
        });

        try {
            $methods = ['PUT', 'PATCH', 'DELETE', 'OPTIONS', 'PURGE', 'MKCOL'];
            $promises = [];

            foreach ($methods as $method) {
                $promises[] = Http::client()->send($method, $url);
            }

            $responses = await(Promise::all($promises));

            foreach ($responses as $index => $response) {
                expect($response->status())->toBe(200)
                    ->and($response->body())->toBe("Method used: {$methods[$index]}")
                ;
            }
        } finally {
            $socket->close();
        }
    });

    it('downgrades to HTTP/1.0 and closes the connection if requested by the client', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Protocol: ' . $request->getProtocolVersion());
        });

        try {
            $response = await(
                Http::client()
                    ->withCurlOption(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0)
                    ->get($url)
            );

            expect($response->status())->toBe(200)
                ->and($response->getHttpVersion())->toBe('1.0')
                ->and($response->body())->toBe('Protocol: 1.0')
            ;
        } finally {
            $socket->close();
        }
    });

    it('aggregates multiple headers with the same name into a comma-separated list', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext($request->getHeaderLine('X-Custom-List'));
        });

        try {
            $response = await(
                Http::client()
                    ->withHeader('X-Custom-List', 'value1')
                    ->withAddedHeader('X-Custom-List', 'value2')
                    ->get($url)
            );

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('value1, value2')
            ;
        } finally {
            $socket->close();
        }
    });

    it('automatically trims leading and trailing whitespace from header values', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext($request->getHeaderLine('X-Trim'));
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\nX-Trim:   trimmed-value   \r\n\r\n");

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'trimmed-value')) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $rawResponse = await($responsePromise);

            expect($rawResponse)->toContain("\r\n\r\ntrimmed-value");
        } finally {
            $socket->close();
        }
    });

    it('matches header lookups case-insensitively', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $h1 = $request->getHeaderLine('x-custom-key');
            $h2 = $request->getHeaderLine('X-CUSTOM-KEY');

            return ServerResponse::plaintext("{$h1}={$h2}");
        });

        try {
            $response = await(
                Http::client()
                    ->withHeader('X-cUsToM-kEy', 'match')
                    ->get($url)
            );

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('match=match')
            ;
        } finally {
            $socket->close();
        }
    });

    it('correctly accepts and parses absolute URIs in the request line', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext($request->getUri());
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET http://localhost/absolute-path HTTP/1.1\r\nHost: localhost\r\n\r\n");

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'absolute-path')) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $rawResponse = await($responsePromise);
            expect($rawResponse)->toContain('http://localhost/absolute-path');
        } finally {
            $socket->close();
        }
    });

    it('safely parses chunked bodies that contain chunk extensions', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext((string) $request->getBody());
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $rawPayload = "POST / HTTP/1.1\r\n"
                . "Host: localhost\r\n"
                . "Transfer-Encoding: chunked\r\n\r\n"
                . "5;foo=bar;baz=123\r\nhello\r\n"
                . "0;checksum=abc\r\n\r\n";

            $connection->write($rawPayload);

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'hello')) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $rawResponse = await($responsePromise);
            expect($rawResponse)->toContain("\r\n\r\nhello");
        } finally {
            $socket->close();
        }
    });

    it('accepts legacy non-ASCII characters (obs-text) in header values', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext($request->getHeaderLine('X-Language'));
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\nX-Language: Français\r\n\r\n");

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'Français')) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $rawResponse = await($responsePromise);
            expect($rawResponse)->toContain('Français');
        } finally {
            $socket->close();
        }
    });

    it('supports true HTTP/1.1 Pipelining (multiple requests sent in a single write)', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext($request->getUri());
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write(
                "GET /pipeline1 HTTP/1.1\r\nHost: localhost\r\n\r\n" .
                    "GET /pipeline2 HTTP/1.1\r\nHost: localhost\r\n\r\n"
            );

            $responsesPromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;

                    if (substr_count($buffer, 'HTTP/1.1 200 OK') === 2) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $rawResponses = await($responsesPromise);

            expect($rawResponses)->toContain('/pipeline1')
                ->and($rawResponses)->toContain('/pipeline2')
            ;
        } finally {
            $socket->close();
        }
    });

    it('correctly reads and parses request bodies on GET requests', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext((string) $request->getBody());
        });

        try {
            $response = await(
                Http::client()
                    ->body('get_body')
                    ->send('GET', $url)
            );

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('get_body')
            ;
        } finally {
            $socket->close();
        }
    });

    it('preserves relative paths and traversal characters verbatim in the request target', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext($request->getUri());
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET /foo/../bar/./baz HTTP/1.1\r\nHost: localhost\r\n\r\n");

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'baz')) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $rawResponse = await($responsePromise);

            expect($rawResponse)->toContain('/foo/../bar/./baz');
        } finally {
            $socket->close();
        }
    });

    it('accepts and preserves query strings containing empty parameters', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext($request->getUri());
        });

        try {
            $response = await(Http::get($url . '/search?q=&filters=&empty'));

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('/search?q=&filters=&empty')
            ;
        } finally {
            $socket->close();
        }
    });

    it('correctly sets Content-Length to 0 for empty string responses', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('');
        });

        try {
            $response = await(Http::get($url));

            expect($response->status())->toBe(200)
                ->and($response->header('content-length'))->toBe('0')
                ->and($response->body())->toBe('')
            ;
        } finally {
            $socket->close();
        }
    });

    it('preserves complex Content-Type parameters with quotes and parameters intact', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext($request->getHeaderLine('Content-Type'));
        });

        try {
            $response = await(
                Http::client()
                    ->contentType('text/html; charset="utf-8"; boundary=boundary_xyz')
                    ->post($url)
            );

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('text/html; charset="utf-8"; boundary=boundary_xyz')
            ;
        } finally {
            $socket->close();
        }
    });

    it('gracefully handles a client disconnecting mid-upload without crashing', function () {
        $uploadFailedSafely = new Promise(function ($resolve) {
            $GLOBALS['resolveUploadFail'] = $resolve;
        });

        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $stream = $request->getBody();

            $stream->on('close', function () {
                if (isset($GLOBALS['resolveUploadFail'])) {
                    $GLOBALS['resolveUploadFail'](true);
                }
            });

            return new ServerResponse(200);
        }, streamingRequests: true);

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Length: 1000\r\n\r\n");
            $connection->write('0123456789');

            await(delay(0.01));

            $connection->close();

            $result = await($uploadFailedSafely);
            expect($result)->toBeTrue();
        } finally {
            unset($GLOBALS['resolveUploadFail']);
            $socket->close();
        }
    });

    it('stops streaming and frees resources if the client disconnects mid-download', function () {
        $streamClosedEarly = new Promise(function ($resolve) {
            $GLOBALS['resolveStreamClosed'] = $resolve;
        });

        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $stream = new ThroughStream();

            $stream->on('close', function () {
                if (isset($GLOBALS['resolveStreamClosed'])) {
                    $GLOBALS['resolveStreamClosed'](true);
                }
            });

            Loop::addPeriodicTimer(0.01, function ($timerId) use ($stream) {
                if (! $stream->isWritable()) {
                    Loop::cancelTimer($timerId);

                    return;
                }
                $stream->write("Endless data...\n");
            });

            return new ServerResponse(200, [], $stream);
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET /download HTTP/1.1\r\nHost: localhost\r\n\r\n");

            $connection->once('data', function () use ($connection) {
                $connection->close();
            });

            $result = await($streamClosedEarly);
            expect($result)->toBeTrue();
        } finally {
            unset($GLOBALS['resolveStreamClosed']);
            $socket->close();
        }
    });

    it('honors HTTP/1.0 non-persistent connections by tearing down the socket after responding', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Legacy Response');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.0\r\nHost: localhost\r\n\r\n");

            $connectionClosedPromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer) {
                    $buffer .= $chunk;
                });

                $connection->on('close', function () use (&$buffer, $resolve) {
                    $resolve($buffer);
                });
            });

            $response = await($connectionClosedPromise);

            expect($response)->toContain('HTTP/1.0 200 OK')
                ->and($response)->toContain('Legacy Response')
            ;
        } finally {
            $socket->close();
        }
    });
});
