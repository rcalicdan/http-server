<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
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

describe('HTTP Server Timeouts & Slowloris Protection', function () {

    it('disconnects slow clients (Slowloris attack) over a real TCP socket', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return new ServerResponse(200, [], 'Success');
        }, headerTimeout: 0.2);

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responseBuffer = '';
            $connection->on('data', function ($chunk) use (&$responseBuffer) {
                $responseBuffer .= $chunk;
            });

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\nX-Slow-Header: ");

            await(delay(0.3));

            expect($responseBuffer)->toContain('HTTP/1.1 408 Request Timeout')
                ->and($responseBuffer)->toContain('Connection: close')
            ;

        } finally {
            $socket->close();
        }
    });

    it('closes persistent connections gracefully after idle time', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return new ServerResponse(200, [], 'OK');
        }, keepAliveTimeout: 0.2);

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $wasClosed = false;
            $connection->on('close', function () use (&$wasClosed) {
                $wasClosed = true;
            });

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

            await(delay(0.05));
            expect($wasClosed)->toBeFalse();

            await(delay(0.2));
            expect($wasClosed)->toBeTrue();

        } finally {
            $socket->close();
        }
    });

});

describe('Timeout Edge Cases', function () {

    it('disconnects completely silent clients on initial connection', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return new ServerResponse(200);
        }, headerTimeout: 0.1);

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $wasClosed = false;
            $connection->on('close', function () use (&$wasClosed) {
                $wasClosed = true;
            });

            await(delay(0.15));
            expect($wasClosed)->toBeTrue();
        } finally {
            $socket->close();
        }
    });

    it('rejects drip-fed headers that take longer than the header timeout limit', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return new ServerResponse(200);
        }, headerTimeout: 0.20);

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responseBuffer = '';
            $connection->on('data', function ($chunk) use (&$responseBuffer) {
                $responseBuffer .= $chunk;
            });

            $connection->write("GET / HTTP/1.1\r\n");
            await(delay(0.15));

            $connection->write("Host: localhost\r\n");
            await(delay(0.15));

            expect($responseBuffer)->toContain('HTTP/1.1 408 Request Timeout');
        } finally {
            $socket->close();
        }
    });

    it('safely handles instant pipelined requests without triggering the keep-alive timeout', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return new ServerResponse(200, [], 'Processed: ' . $request->getUri());
        }, keepAliveTimeout: 0.1);

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responseBuffer = '';
            $connection->on('data', function ($chunk) use (&$responseBuffer) {
                $responseBuffer .= $chunk;
            });

            $payload = "GET /first HTTP/1.1\r\nHost: localhost\r\n\r\n"
                     . "GET /second HTTP/1.1\r\nHost: localhost\r\n\r\n";

            $connection->write($payload);

            await(delay(0.05));

            expect($responseBuffer)->toContain('Processed: /first')
                ->and($responseBuffer)->toContain('Processed: /second')
            ;

            await(delay(0.12));

        } finally {
            $socket->close();
        }
    });

    it('cancels keep-alive idle timer when the first byte of a new request arrives and starts the header timer', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return new ServerResponse(200, [], 'OK');
        }, headerTimeout: 0.2, keepAliveTimeout: 0.2);

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responseBuffer = '';
            $isClosed = false;

            $connection->on('data', function ($chunk) use (&$responseBuffer) {
                $responseBuffer .= $chunk;
            });
            $connection->on('close', function () use (&$isClosed) {
                $isClosed = true;
            });

            $connection->write("GET /first HTTP/1.1\r\nHost: localhost\r\n\r\n");

            await(delay(0.05));
            expect($responseBuffer)->toContain('HTTP/1.1 200 OK');

            $responseBuffer = '';

            await(delay(0.12));
            expect($isClosed)->toBeFalse();

            $connection->write('GET /second HTTP');

            await(delay(0.12));
            expect($isClosed)->toBeFalse();

            await(delay(0.15));

            expect($isClosed)->toBeTrue()
                ->and($responseBuffer)->toContain('HTTP/1.1 408 Request Timeout')
            ;

        } finally {
            $socket->close();
        }
    });

    it('does not trigger header timeout during a slow streaming request body upload', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $body = $request->getBody();
            $deferred = new Promise(function ($resolve) use ($body) {
                $total = 0;
                $body->on('data', function ($chunk) use (&$total) {
                    $total += strlen($chunk);
                });
                $body->on('end', function () use (&$total, $resolve) {
                    $resolve(new ServerResponse(200, [], "Uploaded $total bytes"));
                });
            });

            return await($deferred);
        }, streamingRequests: true, headerTimeout: 0.2);

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responseBuffer = '';
            $connection->on('data', function ($chunk) use (&$responseBuffer) {
                $responseBuffer .= $chunk;
            });

            $connection->write("POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Length: 10\r\n\r\n");

            await(delay(0.15));
            $connection->write('12345');

            await(delay(0.15));
            $connection->write('67890');

            await(delay(0.05));

            expect($responseBuffer)->toContain('HTTP/1.1 200 OK')
                ->and($responseBuffer)->toContain('Uploaded 10 bytes')
            ;
        } finally {
            $socket->close();
        }
    });

    it('does not trigger keep-alive timeout during a slow streaming response download', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $stream = new ThroughStream();
            Loop::addTimer(0.1, function () use ($stream) {
                $stream->write('Part1');
            });
            Loop::addTimer(0.3, function () use ($stream) {
                $stream->write('Part2');
                $stream->end();
            });

            return new ServerResponse(200, [], $stream);
        }, keepAliveTimeout: 0.2);

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responseBuffer = '';
            $connection->on('data', function ($chunk) use (&$responseBuffer) {
                $responseBuffer .= $chunk;
            });

            $connection->write("GET /download HTTP/1.1\r\nHost: localhost\r\n\r\n");

            await(delay(0.4));

            expect($responseBuffer)->toContain('HTTP/1.1 200 OK')
                ->and($responseBuffer)->toContain('Part1')
                ->and($responseBuffer)->toContain('Part2')
            ;
        } finally {
            $socket->close();
        }
    });
});
