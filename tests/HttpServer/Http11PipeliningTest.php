<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\HttpServer;
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\Promise\Promise;
use Hibla\Socket\Connector;
use Hibla\Socket\SocketServer;

use function Hibla\await;
use function Hibla\delay;

afterEach(function () {
    Loop::reset();
});

describe('HttpServer Pipelining & Backpressure Integration', function () {

    it('processes pipelined requests concurrently across Fibers but returns responses in strict FIFO order', function () {
        $log = [];

        [$socket, $url] = createTestServer(function (ServerRequest $request) use (&$log) {
            $uri = $request->getUri();
            $log[] = "start:{$uri}";

            if ($uri === '/slow') {
                await(delay(0.04));
                $log[] = "end:{$uri}";

                return ServerResponse::plaintext('Slow Done');
            }

            $log[] = "end:{$uri}";

            return ServerResponse::plaintext('Fast Done');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responseBuffer = '';
            $connectionOnDataPromise = new Promise(function ($resolve) use ($connection, &$responseBuffer) {
                $connection->on('data', function (string $chunk) use (&$responseBuffer, $resolve) {
                    $responseBuffer .= $chunk;
                    if (substr_count($responseBuffer, 'HTTP/1.1 200 OK') === 2) {
                        $resolve(true);
                    }
                });
            });

            $connection->write(
                "GET /slow HTTP/1.1\r\nHost: localhost\r\n\r\n" .
                "GET /fast HTTP/1.1\r\nHost: localhost\r\n\r\n"
            );

            await($connectionOnDataPromise);
            $connection->close();

            expect($log)->toBe([
                'start:/slow',
                'start:/fast',
                'end:/fast',
                'end:/slow',
            ]);

            $slowPos = strpos($responseBuffer, 'Slow Done');
            $fastPos = strpos($responseBuffer, 'Fast Done');

            expect($slowPos)->toBeLessThan($fastPos);

        } finally {
            $socket->close();
        }
    });

    it('applies real TCP backpressure to the OS socket when maxConcurrentRequests limit is reached', function () {
        $socket = new SocketServer('tcp://127.0.0.1:0');
        $url = str_replace('tcp://', 'http://', $socket->getAddress());

        $deferreds = [];

        HttpServer::attachProtocolHandler(
            $socket,
            function (ServerRequest $request) use (&$deferreds) {
                $uri = $request->getUri();

                return await(new Promise(function ($resolve) use (&$deferreds, $uri) {
                    $deferreds[$uri] = $resolve;
                }));
            },
            maxConcurrentRequestsPerConnection: 1
        );

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET /req1 HTTP/1.1\r\nHost: localhost\r\n\r\n");
            await(delay(0.01));

            expect($deferreds)->toHaveKey('/req1');

            $connection->write("GET /req2 HTTP/1.1\r\nHost: localhost\r\n\r\n");
            await(delay(0.01));

            expect($deferreds)->not->toHaveKey('/req2');

            $deferreds['/req1'](ServerResponse::plaintext('Response 1'));
            await(delay(0.01));

            expect($deferreds)->toHaveKey('/req2');

            $deferreds['/req2'](ServerResponse::plaintext('Response 2'));
            await(delay(0.01));

            $connection->close();

        } finally {
            $socket->close();
        }
    });

    it('seamlessly handles the Expect: 100-continue handshake inside a pipeline of requests', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Body: ' . $request->getBody());
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responseBuffer = '';
            $onDataPromise = new Promise(function ($resolve) use ($connection, &$responseBuffer) {
                $connection->on('data', function (string $chunk) use (&$responseBuffer, $resolve) {
                    $responseBuffer .= $chunk;
                    if (substr_count($responseBuffer, 'HTTP/1.1 200 OK') === 2) {
                        $resolve(true);
                    }
                });
            });

            $connection->write(
                "POST /expect HTTP/1.1\r\nHost: localhost\r\nExpect: 100-continue\r\nContent-Length: 5\r\n\r\nhello" .
                "POST /normal HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\n\r\nworld"
            );

            await($onDataPromise);
            $connection->close();

            expect($responseBuffer)->toContain('HTTP/1.1 100 Continue')
                ->and($responseBuffer)->toContain('Body: hello')
                ->and($responseBuffer)->toContain('Body: world')
            ;

            $continuePos = strpos($responseBuffer, 'HTTP/1.1 100 Continue');
            $helloPos = strpos($responseBuffer, 'Body: hello');
            $worldPos = strpos($responseBuffer, 'Body: world');

            expect($continuePos)->toBeLessThan($helloPos)
                ->and($helloPos)->toBeLessThan($worldPos)
            ;

        } finally {
            $socket->close();
        }
    });

    it('honors HTTP/1.0 Keep-Alive headers during pipelined execution on a real socket', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('URI: ' . $request->getUri() . ' Protocol: ' . $request->getProtocolVersion());
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responseBuffer = '';
            $onDataPromise = new Promise(function ($resolve) use ($connection, &$responseBuffer) {
                $connection->on('data', function (string $chunk) use (&$responseBuffer, $resolve) {
                    $responseBuffer .= $chunk;
                    if (substr_count($responseBuffer, 'HTTP/1.0 200 OK') === 2) {
                        $resolve(true);
                    }
                });
            });

            $connection->write(
                "GET /first HTTP/1.0\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n" .
                "GET /second HTTP/1.0\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n"
            );

            await($onDataPromise);
            $connection->close();

            expect($responseBuffer)->toContain('HTTP/1.0 200 OK')
                ->and($responseBuffer)->toContain('Connection: keep-alive')
                ->and($responseBuffer)->toContain('URI: /first Protocol: 1.0')
                ->and($responseBuffer)->toContain('URI: /second Protocol: 1.0')
            ;

            $firstPos = strpos($responseBuffer, 'URI: /first');
            $secondPos = strpos($responseBuffer, 'URI: /second');

            expect($firstPos)->toBeLessThan($secondPos);

        } finally {
            $socket->close();
        }
    });

    it('aborts processing the pipeline immediately when a request switches protocols (101)', function () {
        $processedCount = 0;

        [$socket, $url] = createTestServer(function (ServerRequest $request, ProtocolHandlerInterface $protocol) use (&$processedCount) {
            $processedCount++;
            if ($request->getUri() === '/upgrade') {
                $protocol->writeResponse(new ServerResponse(101, ['Upgrade' => 'echo', 'Connection' => 'Upgrade']));
                $protocol->detach();

                return null;
            }

            return ServerResponse::plaintext('Normal');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responseBuffer = '';
            $onDataPromise = new Promise(function ($resolve) use ($connection, &$responseBuffer) {
                $connection->on('data', function (string $chunk) use (&$responseBuffer, $resolve) {
                    $responseBuffer .= $chunk;
                    if (str_contains($responseBuffer, '101 Switching Protocols')) {
                        $resolve(true);
                    }
                });
            });

            $connection->write("GET /upgrade HTTP/1.1\r\nHost: localhost\r\nUpgrade: echo\r\nConnection: Upgrade\r\n\r\n");

            await($onDataPromise);

            $connection->write("GET /smuggled HTTP/1.1\r\nHost: localhost\r\n\r\n");
            await(delay(0.01));

            expect($processedCount)->toBe(1);
            $connection->close();
        } finally {
            $socket->close();
        }
    });
});
