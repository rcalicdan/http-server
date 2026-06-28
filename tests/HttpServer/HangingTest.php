<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\Promise\Promise;
use Hibla\Socket\Connector;
use Hibla\Stream\ThroughStream;

use function Hibla\await;

afterEach(function () {
    Loop::reset();
});

it('supports true duplex streaming by piping an incoming request stream directly to an outgoing response stream', function () {
    debug("Test C: Starting (Duplex Echo Proxy)");
    [$socket, $url] = createTestServer(function (ServerRequest $request) {
        debug("Test C: Server received request headers, getting Body Stream...");
        $reqBody = $request->getBody();
        $resBody = new ThroughStream();

        debug("Test C: Server piping RequestBodyStream directly to ThroughStream response...");
        $reqBody->pipe($resBody);

        return new ServerResponse(200, [], $resBody);
    }, maxBodySize: 10485760, streamingRequests: true);

    try {
        $rawClient = new Connector();
        debug("Test C: Connecting client...");
        $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

        debug("Test C: Client writing chunked request headers...");
        $connection->write("POST /duplex HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n");

        debug("Test C: Client writing first body chunk ('hello')...");
        $connection->write("5\r\nhello\r\n");

        $echoPromise = new Promise(function ($resolve) use ($connection) {
            $buffer = '';
            $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                $buffer .= $chunk;
                debug("Test C: Client received data chunk from server!");
                if (str_contains($buffer, 'hello')) {
                    debug("Test C: Client buffer contains 'hello', resolving echo promise.");
                    $resolve($buffer);
                    $connection->close();
                }
            });
        });

        debug("Test C: Awaiting echoed response...");
        $rawResponse = await($echoPromise);

        expect($rawResponse)->toContain('HTTP/1.1 200 OK')
            ->and($rawResponse)->toContain('transfer-encoding: chunked')
            ->and($rawResponse)->toContain("5\r\nhello\r\n");
        debug("Test C: Finished successfully.");
    } finally {
        $socket->close();
    }
});
