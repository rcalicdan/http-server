<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Http;
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\Promise\Promise;
use Hibla\Socket\Connector;
use Hibla\Stream\ThroughStream;

use function Hibla\await;

afterEach(function () {
    Loop::reset();
});

describe('Protocol Compliance & Advanced Features', function () {

    it('uses its own socket address for the URI when no Host header is present on HTTP/1.0', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext((string) $request->getUri());
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET /test HTTP/1.0\r\n\r\n");

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, "\r\n\r\n")) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $rawResponse = await($responsePromise);

            expect($rawResponse)->toContain('HTTP/1.0 200 OK')
                ->and($rawResponse)->toContain('/test')
            ;
        } finally {
            $socket->close();
        }
    });

    it('rejects an HTTP/1.1 request with a 400 Bad Request if the Host header is missing', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            // Intentionally violate RFC 9112 by omitting the Host header on HTTP/1.1
            $connection->write("GET /test HTTP/1.1\r\n\r\n");

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, "\r\n\r\n")) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $rawResponse = await($responsePromise);

            expect($rawResponse)->toContain('HTTP/1.1 400 Bad Request')
                ->and($rawResponse)->toContain('Connection: close')
            ;
        } finally {
            $socket->close();
        }
    });

    it('gives precedence to the Host header for URI construction', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext((string) $request->getUri());
        });

        try {
            $response = await(Http::client()->withHeader('Host', 'api.example.com:8000')->get($url . '/users'));

            expect($response->body())->toBe('/users');
        } finally {
            $socket->close();
        }
    });

    it('handles a basic TLS (HTTPS) request with SSL verification disabled', function () {
        $context = [
            'tls' => ['local_cert' => __DIR__ . '/../Fixtures/localhost.pem'],
        ];
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::json(['secure' => true, 'uri' => (string) $request->getUri()]);
        }, context: $context);

        try {
            $response = await(Http::client()->verifySSL(false)->get($url));

            expect($response->status())->toBe(200)
                ->and($response->json('secure'))->toBeTrue()
            ;
        } finally {
            $socket->close();
        }
    });

    it('handles a basic TLS (HTTPS) request with strict SSL verification enabled', function () {
        $certPath = __DIR__ . '/../Fixtures/localhost.pem';
        $context = [
            'tls' => ['local_cert' => $certPath],
        ];
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::json(['secure' => true]);
        }, context: $context);

        try {
            $secureUrl = str_replace('127.0.0.1', 'localhost', $url);

            $response = await(
                Http::client()
                    ->verifySSL(true)
                    ->withCurlOption(CURLOPT_CAINFO, $certPath)
                    ->get($secureUrl)
            );

            expect($response->status())->toBe(200)
                ->and($response->json('secure'))->toBeTrue()
            ;
        } finally {
            $socket->close();
        }
    });

    it('handles HTTP Upgrade requests for protocol switching (e.g., WebSockets)', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request, ProtocolHandlerInterface $protocol) {
            if (strtolower($request->getHeaderLine('Upgrade')) === 'echo') {
                $response = new ServerResponse(101, ['Upgrade' => 'echo', 'Connection' => 'Upgrade']);
                $protocol->writeResponse($response);

                $connection = $protocol->getConnection();
                $protocol->detach();

                $connection->on('data', function (string $chunk) use ($connection) {
                    $connection->write($chunk);
                });

                return;
            }

            return new ServerResponse(426, [], 'Upgrade Required');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET /chat HTTP/1.1\r\nHost: example.com\r\nConnection: Upgrade\r\nUpgrade: echo\r\n\r\n");

            $switched = new Promise(function ($resolve) use ($connection) {
                $connection->once('data', function ($chunk) use ($resolve) {
                    if (str_contains($chunk, '101 Switching Protocols')) {
                        $resolve(true);
                    }
                });
            });
            await($switched);

            $echoPromise = new Promise(function ($resolve) use ($connection) {
                $connection->once('data', fn ($chunk) => $resolve($chunk));
            });

            $connection->write('Hello, Echo!');
            $echo = await($echoPromise);

            expect($echo)->toBe('Hello, Echo!');
        } finally {
            $socket->close();
        }
    });

    it('handles HTTP CONNECT requests for tunneling', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request, ProtocolHandlerInterface $protocol) {
            if ($request->getMethod() === 'CONNECT') {
                $response = new ServerResponse(200, [], '');
                $protocol->writeResponse($response);

                $connection = $protocol->getConnection();
                $protocol->detach();

                $connection->on('data', fn ($chunk) => $connection->write('Proxied: ' . $chunk));

                return;
            }

            return new ServerResponse(405);
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("CONNECT api.example.com:443 HTTP/1.1\r\nHost: api.example.com:443\r\n\r\n");

            $connected = new Promise(fn ($res) => $connection->once('data', fn ($c) => $res(str_contains($c, '200 OK'))));
            await($connected);

            $echoPromise = new Promise(fn ($res) => $connection->once('data', $res));
            $connection->write('Raw TCP data');

            $echo = await($echoPromise);
            expect($echo)->toBe('Proxied: Raw TCP data');
        } finally {
            $socket->close();
        }
    });

    it('emits close on request body stream if client disconnects mid-upload', function () {
        $closeEventFired = new Promise(fn ($res) => $GLOBALS['resolveClose'] = $res);

        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $request->getBody()->on('close', fn () => $GLOBALS['resolveClose'](true));
        }, streamingRequests: true);

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Length: 10000\r\n\r\nPartial");

            Loop::addTimer(0.01, fn () => $connection->close());

            $wasClosed = await($closeEventFired);
            expect($wasClosed)->toBeTrue();
        } finally {
            unset($GLOBALS['resolveClose']);
            $socket->close();
        }
    });

    it('closes the response stream if the client disconnects mid-download', function () {
        $responseStream = new ThroughStream();
        $streamClosedPromise = new Promise(fn ($res) => $responseStream->on('close', fn () => $res(true)));

        [$socket, $url] = createTestServer(function (ServerRequest $request) use ($responseStream) {
            return new ServerResponse(200, [], $responseStream);
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET /download HTTP/1.1\r\nHost: localhost\r\n\r\n");

            $headersReceived = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, "\r\n\r\n")) {
                        $resolve(true);
                    }
                });
            });

            await($headersReceived);
            $connection->close();

            $wasStreamClosed = await($streamClosedPromise);
            expect($wasStreamClosed)->toBeTrue();
        } finally {
            $socket->close();
        }
    });

    it('sends an empty body immediately if the request handler returns an already closed stream', function () {
        $responseStream = new ThroughStream();
        $responseStream->close();

        [$socket, $url] = createTestServer(function (ServerRequest $request) use ($responseStream) {
            return new ServerResponse(200, [], $responseStream);
        });

        try {
            $response = await(Http::get($url));

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('')
            ;
        } finally {
            $socket->close();
        }
    });

    it('transmits large payloads over TLS without corruption', function () {
        $context = [
            'tls' => ['local_cert' => __DIR__ . '/../Fixtures/localhost.pem'],
        ];

        $largeBody = str_repeat('.', 65536);

        [$socket, $url] = createTestServer(function (ServerRequest $request) use ($largeBody) {
            return ServerResponse::plaintext($largeBody);
        }, context: $context);

        try {
            $response = await(Http::client()->verifySSL(false)->get($url));

            expect($response->status())->toBe(200)
                ->and(strlen($response->body()))->toBe(65536)
                ->and($response->body())->toBe($largeBody)
            ;
        } finally {
            $socket->close();
        }
    });

    it('supports multiple sequential requests on the same TCP connection (HTTP/1.1 Keep-Alive)', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Path: ' . $request->getUri());
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET /first HTTP/1.1\r\nHost: localhost\r\n\r\n");

            $firstResponsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'Path: /first')) {
                        $resolve($buffer);
                        $connection->removeAllListeners('data');
                    }
                });
            });

            $firstResponse = await($firstResponsePromise);
            expect($firstResponse)->toContain('HTTP/1.1 200 OK')
                ->and($firstResponse)->toContain('Path: /first')
            ;

            $connection->write("GET /second HTTP/1.1\r\nHost: localhost\r\n\r\n");

            $secondResponsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'Path: /second')) {
                        $resolve($buffer);
                    }
                });
            });

            $secondResponse = await($secondResponsePromise);
            expect($secondResponse)->toContain('HTTP/1.1 200 OK')
                ->and($secondResponse)->toContain('Path: /second')
            ;

            $connection->close();
        } finally {
            $socket->close();
        }
    });

    it('honors client Connection: close headers and tears down the TCP socket', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Goodbye');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

            $connectionClosedPromise = new Promise(fn ($resolve) => $connection->on('close', fn () => $resolve(true)));

            $wasClosed = await($connectionClosedPromise);
            expect($wasClosed)->toBeTrue();
        } finally {
            $socket->close();
        }
    });

    it('protects against HTTP Response Splitting (CRLF Injection) in outbound headers', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return new ServerResponse(200, [
                'X-Injected-Header' => "value\r\nMalicious-Header: evil\r\nAnother-Header: values",
            ], 'Response OK');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'Response OK')) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $rawResponse = await($responsePromise);

            expect($rawResponse)->not->toContain('Malicious-Header: evil')
                ->and($rawResponse)->toContain('X-Injected-Header: valueMalicious-Header: evilAnother-Header: values')
            ;
        } finally {
            $socket->close();
        }
    });
});
