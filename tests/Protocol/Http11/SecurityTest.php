<?php

declare(strict_types=1);

use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

describe('Chunk size pre-validation against maxBodySize', function () {

    it('rejects immediately when declared chunk-size exceeds maxBodySize before any chunk data arrives', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler(
            $connection,
            function () use (&$requestReached) {
                $requestReached = true;
            },
            maxBodySize: 16
        );

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "ff\r\n"; // declares 255 bytes; maxBodySize is 16 so no data follows

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 413 Payload Too Large')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects when a large chunk-size arrives split across TCP packets, with no data ever sent', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler(
            $connection,
            function () use (&$requestReached) {
                $requestReached = true;
            },
            maxBodySize: 16
        );

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n");
        expect($buffer)->not->toContain('413');

        $handler->handleData("ff\r\n");

        expect($buffer)->toContain('HTTP/1.1 413 Payload Too Large')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('does not accumulate chunk data in the buffer before enforcing the size limit', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler(
            $connection,
            function () use (&$requestReached) {
                $requestReached = true;
            },
            maxBodySize: 16
        );

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\nff\r\n");

        $bufferAfterSizeLine = $buffer;

        $handler->handleData(str_repeat('A', 32));

        expect($buffer)->toBe($bufferAfterSizeLine)
            ->and($buffer)->toContain('HTTP/1.1 413 Payload Too Large')
            ->and($requestReached)->toBeFalse()
        ;
    });
});

describe('hexdec() float overflow on long chunk-size hex strings', function () {

    it('rejects a chunk-size of exactly 16 hex digits with 400 Bad Request', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "8000000000000000\r\n"; // exactly 16 hex digits

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects ffffffffffffffff (max uint64) which overflows to -1 and bypasses size checks', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "ffffffffffffffff\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a chunk-size hex string longer than 16 digits with 400 Bad Request', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "1ffffffffffffffff\r\n"; // 17 hex digits

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('accepts a valid chunk-size with 15 hex digits and handles it without arithmetic corruption', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function () {},
            maxBodySize: 16
        );

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "fffffffffffffff\r\n"; // 15 hex digits, valid format, enormous size

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 413 Payload Too Large')
            ->and($buffer)->not->toContain('400')
        ;
    });
});

describe('Request Smuggling — Connection: close isolation', function () {

    it('does not parse a pipelined request when the incoming request itself carries Connection: close', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestCount = 0;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) use (&$requestCount) {
                $requestCount++;
                $protocol->writeResponse(new Response(200, [], 'OK'));
            }
        );

        $raw = "GET /first HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n"
            . "GET /smuggled HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $handler->handleData($raw);

        expect($requestCount)->toBe(1);
    });

    it('does not parse a pipelined request when the response carries Connection: close', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestCount = 0;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) use (&$requestCount) {
                $requestCount++;
                $protocol->writeResponse(new Response(200, ['Connection' => 'close'], 'OK'));
            }
        );

        $raw = "GET /first HTTP/1.1\r\nHost: localhost\r\n\r\n"
            . "GET /smuggled HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $handler->handleData($raw);

        expect($requestCount)->toBe(1);
    });

    it('silently discards all data arriving in a subsequent handleData call after STATE_UPGRADED', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestCount = 0;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) use (&$requestCount) {
                $requestCount++;
                $protocol->writeResponse(new Response(200, ['Connection' => 'close'], 'OK'));
            }
        );

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        expect($requestCount)->toBe(1);

        $handler->handleData("GET /second HTTP/1.1\r\nHost: localhost\r\n\r\n");
        expect($requestCount)->toBe(1);
    });

    it('discards data arriving after a 400 error transitions the state machine to STATE_UPGRADED', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestCount = 0;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestCount) {
            $requestCount++;
        });

        $handler->handleData("INVALID\r\n\r\nGET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestCount)->toBe(0)
        ;
    });
});

describe('Request Smuggling — TE.TE double-chunked attack', function () {

    it('rejects Transfer-Encoding: chunked, chunked to prevent TE.TE body boundary desync', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked, chunked\r\n"
            . "\r\n"
            . "5\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });
});

describe('Header Field Security — Token rule and control character injection', function () {

    it('rejects a null byte (\\x00) in a header field name', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nX-\x00Evil: value\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a null byte (\\x00) in a header field value', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nX-Custom: val\x00ue\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a tab character (\\x09) in a header field name', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nX-\x09Custom: value\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a DEL character (\\x7F) in a header field name', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nX-\x7FCustom: value\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a header line that begins with a colon (empty field name)', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n: injected-value\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a header field name containing a delimiter character such as a parenthesis', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nX-(Custom: value\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });
});

describe('Header Field Security — Size and Count limits', function () {

    it('rejects requests exceeding the maximum configured header count', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler(
            $connection,
            function () use (&$requestReached) {
                $requestReached = true;
            },
            maxHeaderCount: 5
        );

        $raw = "GET / HTTP/1.1\r\nHost: localhost\r\n";
        for ($i = 0; $i < 6; $i++) {
            $raw .= "X-Custom-{$i}: value\r\n";
        }
        $raw .= "\r\n";

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 431 Request Header Fields Too Large')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('accepts requests exactly at the maximum header count', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request) use (&$parsedRequest) {
                $parsedRequest = $request;
            },
            maxHeaderCount: 5
        );

        $raw = "GET / HTTP/1.1\r\nHost: localhost\r\n";
        for ($i = 0; $i < 4; $i++) {
            $raw .= "X-Custom-{$i}: value\r\n";
        }
        $raw .= "\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and(count($parsedRequest->getHeaders()))->toBe(5)
        ;
    });

    it('rejects requests with header names exceeding the maximum allowed length (256 bytes)', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $longHeaderName = str_repeat('X', 257);
        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n{$longHeaderName}: value\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 431 Request Header Fields Too Large')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('accepts requests with header names exactly at the maximum allowed length', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $longHeaderName = str_repeat('X', 256);
        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n{$longHeaderName}: value\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->hasHeader($longHeaderName))->toBeTrue()
        ;
    });
});

describe('Request Method — Token rule validation', function () {

    it('rejects a request method containing a delimiter character such as an angle bracket', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GE<T / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });
});

describe('Content-Length — Edge cases and overflow attacks', function () {

    it('accepts Content-Length: 0 on a POST as a valid bodyless request', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("POST /submit HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('')
        ;
    });

    it('rejects a Content-Length value with a leading plus sign', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: +5\r\n\r\nhello");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a Content-Length value that would overflow PHP integer arithmetic', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 99999999999999999999\r\n\r\n");

        expect($buffer)->toMatch('/HTTP\/1\.1 (400|413)/')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('correctly parses Content-Length with leading zeros as a valid decimal value', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 007\r\n\r\nhello w");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('hello w')
        ;
    });
});

describe('Chunked Encoding — Edge cases', function () {

    it('handles an immediate terminal zero-chunk as a valid empty chunked body', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "0\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('')
        ;
    });

    it('handles a terminal zero-chunk with a chunk extension without corrupting trailer parsing', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "5\r\nhello\r\n"
            . "0;checksum=abc123\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('hello')
        ;
    });

    it('handles chunk data containing raw null bytes without truncation or corruption', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $binaryPayload = "he\x00lo";

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "5\r\n{$binaryPayload}\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe($binaryPayload)
        ;
    });

    it('accepts Transfer-Encoding: CHUNKED (fully uppercase) as equivalent to chunked', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: CHUNKED\r\n"
            . "\r\n"
            . "5\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('hello')
        ;
    });

    it('accepts multiple Transfer-Encoding header lines where chunked is in the final line', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: gzip\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "5\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getBody())->toBe('hello')
        ;
    });
});

describe('HTTP Version — Grammar edge cases', function () {

    it('accepts HTTP/0.9 as syntactically valid per the HTTP/DIGIT.DIGIT grammar', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("GET / HTTP/0.9\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getProtocolVersion())->toBe('0.9')
        ;
    });

    it('accepts HTTP/9.9 as syntactically valid per the HTTP/DIGIT.DIGIT grammar', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("GET / HTTP/9.9\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getProtocolVersion())->toBe('9.9')
        ;
    });

    it('rejects HTTP/1.10 because the fixed eight-character version format forbids two-digit minor numbers', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET / HTTP/1.10\r\nHost: localhost\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });
});

describe('HTTP/1.0 — Specific compliance', function () {

    it('accepts an HTTP/1.0 request without a Host header (Host is only mandatory in HTTP/1.1)', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) use (&$parsedRequest) {
                $parsedRequest = $request;
                $protocol->writeResponse(new Response(200, [], 'OK'));
            }
        );

        $handler->handleData("GET / HTTP/1.0\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getProtocolVersion())->toBe('1.0')
        ;
    });

    it('mirrors HTTP/1.0 in the response status line when the request version is HTTP/1.0', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'OK'));
            }
        );

        $handler->handleData("GET / HTTP/1.0\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.0 200 OK')
            ->and($buffer)->not->toContain('HTTP/1.1')
        ;
    });
});

describe('GET with body — Framing correctness', function () {

    it('reads a body on a GET request when Content-Length is present without desynchronizing', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "GET / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: 4\r\n"
            . "\r\n"
            . 'body';

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getMethod())->toBe('GET')
            ->and($parsedRequest->getBody())->toBe('body')
        ;
    });

    it('correctly parses a pipelined request arriving immediately after a GET with a body', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequests = [];
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequests) {
            $parsedRequests[] = $request;
        });

        $raw = "GET /first HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: 4\r\n"
            . "\r\n"
            . 'body'
            . "GET /second HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "\r\n";

        $handler->handleData($raw);

        expect($parsedRequests)->toHaveCount(2)
            ->and($parsedRequests[0]->getUri())->toBe('/first')
            ->and($parsedRequests[1]->getUri())->toBe('/second')
        ;
    });
});

describe('State machine — Per-request state reset integrity', function () {

    it('resets all per-request counters cleanly so a second chunked request in a pipeline parses correctly', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $bodies = [];
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$bodies) {
            $bodies[] = $request->getBody();
        });

        $raw = "POST /first HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "3\r\nfoo\r\n0\r\n\r\n"
            . "POST /second HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "3\r\nbar\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($bodies)->toHaveCount(2)
            ->and($bodies[0])->toBe('foo')
            ->and($bodies[1])->toBe('bar')
        ;
    });

    it('resets all per-request counters cleanly so a second fixed-length request in a pipeline parses correctly', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $bodies = [];
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$bodies) {
            $bodies[] = $request->getBody();
        });

        $raw = "POST /first HTTP/1.1\r\nHost: localhost\r\nContent-Length: 3\r\n\r\nfoo"
            . "POST /second HTTP/1.1\r\nHost: localhost\r\nContent-Length: 3\r\n\r\nbar";

        $handler->handleData($raw);

        expect($bodies)->toHaveCount(2)
            ->and($bodies[0])->toBe('foo')
            ->and($bodies[1])->toBe('bar')
        ;
    });

    it('does not invoke the request handler after a 413 has already closed the connection mid-body', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestCount = 0;
        $handler = new Http11ProtocolHandler(
            $connection,
            function () use (&$requestCount) {
                $requestCount++;
            },
            maxBodySize: 5
        );

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: 10\r\n"
            . "\r\n"
            . 'helloworld';

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 413 Payload Too Large')
            ->and($requestCount)->toBe(0)
        ;
    });
});

describe('Request Target — control character injection', function () {

    it('rejects a request target containing a null byte', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET /path\x00evil HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a request target containing a bare CR', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET /path\revil HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });
});

describe('Content-Length — PHP integer saturation at upper boundary', function () {

    it('rejects Content-Length of PHP_INT_MAX + 1 as Payload Too Large without integer saturation bypass', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 9223372036854775808\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 413')
            ->and($requestReached)->toBeFalse()
        ;
    });
});
