<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Http;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\Promise\Promise;
use Hibla\Socket\Connector;

use function Hibla\await;

afterEach(function () {
    Loop::reset();
});

describe('Server Security Limits', function () {
    it('rejects real requests over TCP that exceed the maxHeaderCount limit with a 431', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        }, maxHeaderCount: 5);

        try {
            $client = Http::client();
            for ($i = 0; $i < 10; $i++) {
                $client = $client->withHeader("X-Custom-{$i}", 'Value');
            }

            try {
                $response = await($client->get($url));
                expect($response->status())->toBe(431);
            } catch (NetworkException $e) {
                expect($e->getMessage())->not->toBeEmpty();
            }
        } finally {
            $socket->close();
        }
    });

    it('rejects requests exceeding maxBodySize with a 413 Payload Too Large', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('This should not be reached');
        }, maxBodySize: 1024);

        try {
            $largePayload = str_repeat('A', 2048);

            try {
                $response = await(Http::post($url, ['data' => $largePayload]));
                expect($response->status())->toBe(413);
            } catch (NetworkException $e) {
                expect($e->getMessage())->not->toBeEmpty();
            }
        } finally {
            $socket->close();
        }
    });

    it('rejects requests exceeding maxHeaderSize with a 431 Request Header Fields Too Large', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('This should not be reached');
        }, maxHeaderSize: 1024);

        try {
            $massiveHeaderValue = str_repeat('B', 2048);

            try {
                $response = await(
                    Http::client()
                        ->withHeader('X-Massive-Header', $massiveHeaderValue)
                        ->get($url)
                );
                expect($response->status())->toBe(431);
            } catch (NetworkException $e) {
                expect($e->getMessage())->not->toBeEmpty();
            }
        } finally {
            $socket->close();
        }
    });

    it('rejects HTTP/1.1 requests containing multiple Host headers with a 400 Bad Request', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.1\r\nHost: target1.com\r\nHost: target2.com\r\n\r\n");

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

            $response = await($responsePromise);
            expect($response)->toContain('HTTP/1.1 400 Bad Request')
                ->and($response)->toContain('Connection: close')
            ;
        } finally {
            $socket->close();
        }
    });

    it('rejects requests containing obsolete line folding (obs-fold) with a 400 Bad Request', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\nX-Fold: first-part\r\n second-part\r\n\r\n");

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

            $response = await($responsePromise);
            expect($response)->toContain('HTTP/1.1 400 Bad Request');
        } finally {
            $socket->close();
        }
    });

    it('rejects requests with whitespace between header field name and colon with 400 Bad Request', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\nX-Header-Space : value\r\n\r\n");

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

            $response = await($responsePromise);
            expect($response)->toContain('HTTP/1.1 400 Bad Request');
        } finally {
            $socket->close();
        }
    });

    it('rejects requests containing null bytes inside header values with 400 Bad Request', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\nX-Poison: value\x00injected\r\n\r\n");

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

            $response = await($responsePromise);
            expect($response)->toContain('HTTP/1.1 400 Bad Request');
        } finally {
            $socket->close();
        }
    });

    it('rejects Transfer-Encoding containing duplicate chunked elements with 400 Bad Request', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked, chunked\r\n\r\n0\r\n\r\n");

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

            $response = await($responsePromise);
            expect($response)->toContain('HTTP/1.1 400 Bad Request');
        } finally {
            $socket->close();
        }
    });

    it('rejects requests containing bare Carriage Returns in headers with 400 Bad Request', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\nX-Bare: value\rstill_on_same_line\r\n\r\n");

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

            $response = await($responsePromise);
            expect($response)->toContain('HTTP/1.1 400 Bad Request');
        } finally {
            $socket->close();
        }
    });

    it('rejects requests where a single header field name exceeds 256 bytes with 431', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $longHeaderName = str_repeat('X', 257);
            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\n{$longHeaderName}: value\r\n\r\n");

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

            $response = await($responsePromise);
            expect($response)->toContain('HTTP/1.1 431 Request Header Fields Too Large');
        } finally {
            $socket->close();
        }
    });

    it('rejects arithmetic-overflowing hex chunk sizes (e.g. max uint64) with 400 Bad Request', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\nffffffffffffffff\r\nbody\r\n0\r\n\r\n");

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

            $response = await($responsePromise);
            expect($response)->toContain('HTTP/1.1 400 Bad Request');
        } finally {
            $socket->close();
        }
    });

    it('rejects Content-Length headers containing non-digit characters with a 400 Bad Request', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5abc\r\n\r\nhello");

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

            $response = await($responsePromise);
            expect($response)->toContain('HTTP/1.1 400 Bad Request');
        } finally {
            $socket->close();
        }
    });

});
