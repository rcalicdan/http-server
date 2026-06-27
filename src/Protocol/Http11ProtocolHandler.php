<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Protocol;

use Hibla\HttpServer\Exceptions\MessageParsingException;
use Hibla\HttpServer\Exceptions\PayloadTooLargeException;
use Hibla\HttpServer\Exceptions\UnsupportedTransferCodingException;
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\RequestBodyStream;
use Hibla\HttpServer\Message\Response;
use Hibla\Socket\Interfaces\ConnectionInterface;

class Http11ProtocolHandler implements ProtocolHandlerInterface
{
    private const int STATE_HEADERS = 0;

    private const int STATE_BODY_LENGTH = 1;

    private const int STATE_CHUNK_SIZE = 2;

    private const int STATE_CHUNK_DATA = 3;

    private const int STATE_UPGRADED = 4;

    private const int STATE_CHUNK_TRAILER = 5;

    private const int MAX_HEADER_SIZE = 8192;

    private const int MAX_HEADER_CACHE_SIZE = 512;

    private const int MAX_CHUNK_LINE_SIZE = 1024;

    private const int MAX_STREAMING_CHUNK_SIZE = 16777216;

    private bool $isChunked = false;

    private bool $willCloseConnection = false;

    private int $state = self::STATE_HEADERS;

    private int $expectedBodyLength = 0;

    private int $bytesRead = 0;

    private int $currentChunkSize = 0;

    private int $chunkBytesRead = 0;

    private int $bufferOffset = 0;

    private int $bufferedBodyBytes = 0;

    private ?Request $currentRequest = null;

    private ?RequestBodyStream $bodyStream = null;

    private string $activeResponseVersion = '1.1';

    private string $buffer = '';

    /**
     * @var list<string>
     */
    private array $bodyChunks = [];

    /**
     * Per-process cache mapping lowercase wire-format header names (e.g. "content-type")
     * to their display-formatted equivalents (e.g. "Content-Type"). Populated lazily on
     * first encounter, eliminating repeated ucwords/str_replace calls for the same header
     * name across every response in the process lifetime.
     *
     * @var array<string, string>
     */
    private static array $headerNameCache = [];

    /**
     * @param ConnectionInterface $connection The raw TCP/TLS connection
     * @param callable(Request, ProtocolHandlerInterface): void $onRequest Callback triggered when a full request is parsed
     * @param int $maxBodySize Maximum body size in bytes for buffered (non-streaming) requests.
     *                         In buffered mode this governs both the per-chunk cap (chunked TE)
     *                         and the total accumulated body limit. In streaming mode neither
     *                         limit applies so the application layer controls consumption and
     *                         back-pressure via the RequestBodyStream pause/resume interface.
     *                         Individual chunk sizes in streaming mode are independently capped
     *                         at MAX_STREAMING_CHUNK_SIZE as a memory safety bound.
     * @param bool $streamingRequests True to enable streaming request bodies
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly mixed $onRequest,
        private readonly int $maxBodySize = 10485760,
        private readonly bool $streamingRequests = false
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * @inheritDoc
     *
     * Appends incoming TCP data to the internal receive buffer and drives the protocol
     * state machine forward. Two key optimisations over the naive implementation:
     *
     *  1. Phase dispatch uses a match expression (compiled to a jump table by OPcache)
     *     rather than a sequential if-chain, removing O(state-index) comparisons per tick.
     *
     *  2. Buffer consumption is tracked via $bufferOffset rather than repeated substr()
     *     slices, so the receive buffer is only reallocated once per handleData() call
     *     (in compactBuffer()) instead of once per phase transition.
     */
    public function handleData(string $data): void
    {
        if ($this->state === self::STATE_UPGRADED) {
            return;
        }

        $this->buffer .= $data;

        while ($this->bufferOffset < \strlen($this->buffer)) {
            $advanced = match ($this->state) {
                self::STATE_HEADERS => $this->parseHeadersPhase(),
                self::STATE_BODY_LENGTH => $this->parseBodyLengthPhase(),
                self::STATE_CHUNK_SIZE => $this->parseChunkSizePhase(),
                self::STATE_CHUNK_DATA => $this->parseChunkDataPhase(),
                self::STATE_CHUNK_TRAILER => $this->parseChunkTrailerPhase(),
                default => false,
            };

            if (! $advanced) {
                break;
            }
        }

        // Compact the dead consumed prefix exactly once per TCP read event — rather than
        // after every phase transition and keeping total allocation to a single substr call.
        $this->compactBuffer();
    }

    /**
     * Writes an HTTP response back to the underlying connection.
     *
     * Applies the following RFC 9112 connection persistence rules:
     * - section 9.3: The response status line must mirror the request's protocol version.
     * - section 9.3: HTTP/1.0 connections are non-persistent by default; closed unless keep-alive is requested.
     * - section 9.6: A response carrying Connection: close triggers immediate connection teardown.
     *
     * @param Response $response
     *
     * @inheritDoc
     */
    public function writeResponse(Response $response): void
    {
        // RFC 9931 section 8: a rejected CONNECT MUST force connection closure to prevent
        // optimistically-pipelined data from being parsed as subsequent HTTP requests.
        if (
            $this->currentRequest !== null
            && $this->currentRequest->getMethod() === 'CONNECT'
            && $response->getStatusCode() >= 400
        ) {
            $this->willCloseConnection = true;
        }

        $body = $response->getBody();
        $isStreamingOut = ! \is_string($body);
        $version = $response->getProtocolVersion() === '1.1' ? $this->activeResponseVersion : $response->getProtocolVersion();
        $headers = $response->getHeaders();

        if (! isset($headers['server'])) {
            $headers['server'] = ['Hibla/1.0'];
        }

        $isChunkedResponse = false;

        if ($isStreamingOut && ! isset($headers['content-length'])) {
            $isChunkedResponse = true;
            $headers['transfer-encoding'] = ['chunked'];
        } elseif (! $isStreamingOut && ! isset($headers['content-length']) && $response->getStatusCode() !== 101) {
            $headers['content-length'] = [(string) \strlen((string) $body)];
        }

        $shouldClose = $this->willCloseConnection || strtolower($response->getHeaderLine('connection')) === 'close';

        if ($shouldClose && ! isset($headers['connection'])) {
            $headers['connection'] = ['close'];
        } elseif (! $shouldClose && $version === '1.0' && ! isset($headers['connection'])) {
            $headers['connection'] = ['keep-alive'];
        }

        $lines = ["HTTP/{$version} {$response->getStatusCode()} {$response->getReasonPhrase()}"];

        foreach ($headers as $name => $values) {
            $displayName = self::formatHeaderNameForWire($name);
            foreach ((array) $values as $value) {
                $lines[] = "{$displayName}: {$value}";
            }
        }

        $headerBlock = implode("\r\n", $lines) . "\r\n\r\n";

        if (\is_string($body)) {
            $this->connection->write($headerBlock . $body);
            if ($shouldClose) {
                $this->connection->close();
                // Prevent the state machine from parsing any bytes still in the buffer
                // after a connection close (e.g. optimistically-pipelined data after
                // a rejected CONNECT per RFC 9931 section 8, or any Connection: close tear-down).
                $this->state = self::STATE_UPGRADED;
            }
        } else {
            $this->connection->write($headerBlock);

            $body->on('data', function (string $chunk) use ($isChunkedResponse) {
                if ($isChunkedResponse) {
                    $this->connection->write(dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n");
                } else {
                    $this->connection->write($chunk);
                }
            });

            $body->on('end', function () use ($isChunkedResponse, $shouldClose) {
                if ($isChunkedResponse) {
                    $this->connection->write("0\r\n\r\n");
                }
                if ($shouldClose) {
                    $this->connection->close();
                    $this->state = self::STATE_UPGRADED;
                }
            });

            $body->on('error', function () {
                $this->connection->close();
            });
        }
    }

    /**
     * @inheritDoc
     */
    public function detach(): string
    {
        $this->state = self::STATE_UPGRADED;

        $remaining = $this->bufferOffset < \strlen($this->buffer)
            ? substr($this->buffer, $this->bufferOffset)
            : '';

        $this->buffer = '';
        $this->bufferOffset = 0;

        return $remaining;
    }

    /**
     * Extracts the raw header block from the incoming TCP buffer.
     *
     * Complies with RFC 9112 section 2.2 by stripping any leading empty lines (bare CRLFs)
     * before attempting to locate the request-line. Protects against buffer bloat
     * by enforcing a maximum header size.
     *
     * @return bool True if headers were successfully parsed or buffer advanced, false otherwise.
     */
    private function parseHeadersPhase(): bool
    {
        $bufLen = \strlen($this->buffer);

        while (
            $this->bufferOffset < $bufLen
            && ($this->buffer[$this->bufferOffset] === "\r" || $this->buffer[$this->bufferOffset] === "\n")
        ) {
            $this->bufferOffset++;
        }

        $available = $bufLen - $this->bufferOffset;

        if ($available === 0) {
            return false;
        }

        $headerEndPos = strpos($this->buffer, "\r\n\r\n", $this->bufferOffset);

        if ($headerEndPos === false) {
            if ($available > self::MAX_HEADER_SIZE) {
                $this->sendErrorAndClose(431, 'Request Header Fields Too Large');
            }

            return false;
        }

        if ($headerEndPos - $this->bufferOffset > self::MAX_HEADER_SIZE) {
            $this->sendErrorAndClose(431, 'Request Header Fields Too Large');

            return false;
        }

        $rawHeaders = substr($this->buffer, $this->bufferOffset, $headerEndPos - $this->bufferOffset);
        $this->bufferOffset = $headerEndPos + 4;

        try {
            $this->parseHeaders($rawHeaders);
        } catch (PayloadTooLargeException) {
            $this->sendErrorAndClose(413, 'Payload Too Large');

            return false;
        } catch (UnsupportedTransferCodingException) {
            $this->sendErrorAndClose(501, 'Not Implemented');

            return false;
        } catch (\Throwable) {
            $this->sendErrorAndClose(400, 'Bad Request');

            return false;
        }

        if ($this->currentRequest === null) {
            return false;
        }

        // RFC 9110 Section 10.1.1: Automatically signal the client to send the body
        // if the headers were successfully parsed and accepted.
        if (strtolower($this->currentRequest->getHeaderLine('expect')) === '100-continue') {
            $this->connection->write("HTTP/1.1 100 Continue\r\n\r\n");
        }

        if ($this->streamingRequests) {
            $this->bodyStream = new RequestBodyStream();
            $this->bodyStream->on('pause', $this->connection->pause(...));
            $this->bodyStream->on('resume', $this->connection->resume(...));
            $this->currentRequest->setBody($this->bodyStream);

            if (\is_callable($this->onRequest)) {
                ($this->onRequest)($this->currentRequest, $this);
            }
        }

        if ($this->isChunked) {
            $this->state = self::STATE_CHUNK_SIZE;
        } elseif ($this->expectedBodyLength > 0) {
            $this->state = self::STATE_BODY_LENGTH;
        } else {
            $this->finalizeRequest();
        }

        return true;
    }

    /**
     * Reads a fixed-length request body from the buffer.
     *
     * Uses min() to derive the consumable chunk size in a single expression,
     * avoiding a redundant strlen() call on a freshly allocated substr result.
     */
    private function parseBodyLengthPhase(): bool
    {
        $bufLen = \strlen($this->buffer);
        $remaining = $this->expectedBodyLength - $this->bytesRead;
        $available = $bufLen - $this->bufferOffset;
        $chunkLength = min($remaining, $available);

        if ($chunkLength === 0) {
            return false;
        }

        $chunk = substr($this->buffer, $this->bufferOffset, $chunkLength);
        $this->bufferOffset += $chunkLength;
        $this->bytesRead += $chunkLength;

        if (! $this->pushBodyData($chunk)) {
            return false;
        }

        if ($this->bytesRead >= $this->expectedBodyLength) {
            $this->finalizeRequest();
        }

        return $this->bufferOffset < $bufLen;
    }

    /**
     * Parses the hex size of the next chunk in a chunked transfer encoding payload.
     *
     * Complies with RFC 9112 section 7.1.2 by transitioning the state machine to consume
     * the trailer section once the terminal zero-size chunk is encountered.
     *
     * Enforces strict ctype_xdigit verification to mitigate TE.TE smuggling vectors.
     *
     * @return bool True if a chunk size was parsed, false if waiting for more data.
     */
    private function parseChunkSizePhase(): bool
    {
        $pos = strpos($this->buffer, "\r\n", $this->bufferOffset);

        if ($pos === false) {
            if (\strlen($this->buffer) - $this->bufferOffset > self::MAX_CHUNK_LINE_SIZE) {
                $this->sendErrorAndClose(400, 'Bad Request');
            }

            return false;
        }

        $line = substr($this->buffer, $this->bufferOffset, $pos - $this->bufferOffset);
        $this->bufferOffset = $pos + 2;
        $extPos = strpos($line, ';');
        $hex = $extPos !== false ? rtrim(substr($line, 0, $extPos)) : trim($line);

        if ($hex === '' || ! ctype_xdigit($hex)) {
            $this->sendErrorAndClose(400, 'Bad Request');

            return false;
        }

        //hex strings >= 16 digits exceed PHP_INT_MAX on 64-bit systems.
        // hexdec() silently returns a float; (int) cast produces platform-dependent
        // garbage (typically -1 or PHP_INT_MIN) that corrupts the chunk-data read path.
        if (\strlen($hex) > 15) {
            $this->sendErrorAndClose(400, 'Bad Request');

            return false;
        }

        $this->currentChunkSize = (int) hexdec($hex);

        if ($this->currentChunkSize === 0) {
            $this->state = self::STATE_CHUNK_TRAILER;

            return $this->parseChunkTrailerPhase();
        }

        // Enforce a per-chunk size cap before entering STATE_CHUNK_DATA.
        // Without this, a client that declares a chunk larger than the cap and then
        // dribbles data holds the connection open while the buffer grows unbounded and
        // parseChunkDataPhase() waits for the full declared size and pushBodyData()'s
        // accumulated-size check is never reached.
        //
        // The cap differs by mode because maxBodySize means different things:
        //   - Buffered mode: maxBodySize is both the per-chunk and total body limit.
        //   - Streaming mode: maxBodySize is intentionally not enforced as a body limit
        //     (the application layer owns that policy via back-pressure). A separate
        //     constant caps individual chunk sizes purely as a memory safety bound.
        $chunkSizeLimit = $this->streamingRequests
            ? self::MAX_STREAMING_CHUNK_SIZE
            : $this->maxBodySize;

        if ($this->currentChunkSize > $chunkSizeLimit) {
            $this->sendErrorAndClose(413, 'Payload Too Large');

            return false;
        }

        $this->state = self::STATE_CHUNK_DATA;

        return true;
    }

    /**
     * Parses payload chunk data up to the previously extracted chunk size.
     */
    private function parseChunkDataPhase(): bool
    {
        $available = \strlen($this->buffer) - $this->bufferOffset;

        if ($available === 0) {
            return false;
        }

        if ($this->streamingRequests) {
            $remaining = $this->currentChunkSize - $this->chunkBytesRead;
            $canRead = min($remaining, $available);

            if ($canRead > 0) {
                $chunk = substr($this->buffer, $this->bufferOffset, $canRead);
                $this->bufferOffset += $canRead;
                $this->chunkBytesRead += $canRead;

                if (! $this->pushBodyData($chunk)) {
                    return false;
                }
            }

            if ($this->chunkBytesRead >= $this->currentChunkSize) {
                if (\strlen($this->buffer) - $this->bufferOffset < 2) {
                    return false;
                }

                $this->bufferOffset += 2;
                $this->chunkBytesRead = 0;
                $this->state = self::STATE_CHUNK_SIZE;

                return true;
            }

            return false;
        }

        if ($available >= $this->currentChunkSize + 2) {
            $chunk = substr($this->buffer, $this->bufferOffset, $this->currentChunkSize);

            if (! $this->pushBodyData($chunk)) {
                return false;
            }

            $this->bufferOffset += $this->currentChunkSize + 2;
            $this->state = self::STATE_CHUNK_SIZE;

            return true;
        }

        return false;
    }

    /**
     * Consumes the chunked trailer section (RFC 9112 section 7.1.2).
     *
     * @return bool True if the trailer section was fully consumed, false otherwise.
     */
    private function parseChunkTrailerPhase(): bool
    {
        $bufLen = \strlen($this->buffer);
        $available = $bufLen - $this->bufferOffset;

        if (
            $available >= 2
            && $this->buffer[$this->bufferOffset] === "\r"
            && $this->buffer[$this->bufferOffset + 1] === "\n"
        ) {
            $this->bufferOffset += 2;
            $this->finalizeRequest();

            return $this->bufferOffset < $bufLen;
        }

        $trailerEnd = strpos($this->buffer, "\r\n\r\n", $this->bufferOffset);

        if ($trailerEnd === false) {
            if ($available > self::MAX_HEADER_SIZE) {
                $this->sendErrorAndClose(400, 'Bad Request');
            }

            return false;
        }

        $this->bufferOffset = $trailerEnd + 4;
        $this->finalizeRequest();

        return $this->bufferOffset < $bufLen;
    }

    /**
     * Pushes a body chunk to either the live stream or the buffered chunk list.
     *
     * @return bool False if this push triggered a 413 and closed the connection
     */
    private function pushBodyData(string $data): bool
    {
        if ($this->currentRequest === null) {
            return true;
        }

        if ($this->streamingRequests && $this->bodyStream !== null) {
            $this->bodyStream->push($data);

            return true;
        }

        $this->bufferedBodyBytes += \strlen($data);

        if ($this->bufferedBodyBytes > $this->maxBodySize) {
            $this->sendErrorAndClose(413, 'Payload Too Large');

            return false;
        }

        $this->bodyChunks[] = $data;

        return true;
    }

    private function finalizeRequest(): void
    {
        if ($this->currentRequest === null) {
            return;
        }

        if ($this->streamingRequests && $this->bodyStream !== null) {
            $this->bodyStream->end();
        } else {
            $this->currentRequest->setBody(implode('', $this->bodyChunks));

            if (\is_callable($this->onRequest)) {
                ($this->onRequest)($this->currentRequest, $this);
            }
        }

        if ($this->state !== self::STATE_UPGRADED) {
            $this->state = self::STATE_HEADERS;
        }

        $this->currentRequest = null;
        $this->bodyStream = null;
        $this->bodyChunks = [];
        $this->isChunked = false;
        $this->expectedBodyLength = 0;
        $this->bytesRead = 0;
        $this->bufferedBodyBytes = 0;
    }

    /**
     * Parses the raw header block into a Request value object.
     *
     * This method strictly applies RFC 9112 structural validations. Violations result
     * in a MessageParsingException, which triggers a 400 Bad Request response.
     *
     * Enforced RFC 9112 rules:
     * - section 2.2: Absorbs any stray empty lines between the start of the message and the request-line.
     * - section 2.2: Rejects a bare CR (a CR not immediately followed by LF) within the request-line or header field line.
     * - section 2.3: Validates HTTP version format and case sensitivity.
     * - section 3.1 / 5.5: Enforces strict VCHAR/Token validation to block control characters in methods and headers.
     * - section 3.2: Enforces that the Host header is mandatory, singular, and does not contain a comma-separated list.
     * - section 5.1: Rejects whitespace between field name and colon, or lines with no colon.
     * - section 5.2: Rejects Obsolete Line Folding (obs-fold) to prevent request smuggling.
     * - section 6.1: Enforces mandatory connection closure if both Transfer-Encoding and Content-Length are present.
     * - section 6.3: Validates TE chains. 400 Bad Request if recognized but not chunked-terminated, 501 if unrecognized.
     * - section 6.3: Removes Content-Length entirely if Transfer-Encoding is present.
     *
     * @param string $rawHeaders The raw HTTP header string including the request line.
     *
     * @throws MessageParsingException If any structural validation fails.
     * @throws UnsupportedTransferCodingException If Transfer-Encoding names an unrecognized coding.
     * @throws PayloadTooLargeException If the calculated Content-Length exceeds the configured maximum body size.
     */
    private function parseHeaders(string $rawHeaders): void
    {
        $firstCrLf = strpos($rawHeaders, "\r\n");
        $requestLine = $firstCrLf !== false ? substr($rawHeaders, 0, $firstCrLf) : $rawHeaders;
        $headerBody = $firstCrLf !== false ? substr($rawHeaders, $firstCrLf + 2) : '';

        if ($requestLine === '') {
            throw new MessageParsingException('Empty request line');
        }

        if (str_contains($requestLine, "\r")) {
            throw new MessageParsingException('Bare CR is not permitted in the request line');
        }

        if (substr_count($requestLine, ' ') !== 2) {
            throw new MessageParsingException('Invalid Request Line');
        }

        $s1 = (int) strpos($requestLine, ' ');
        $s2 = (int) strrpos($requestLine, ' ');

        $method = substr($requestLine, 0, $s1);
        $target = substr($requestLine, $s1 + 1, $s2 - $s1 - 1);
        $version = substr($requestLine, $s2 + 1);

        if (preg_match('/[\x00-\x1F\x7F]/', $method) === 1) {
            throw new MessageParsingException('Invalid control character in request method');
        }

        if (
            \strlen($version) !== 8
            || strncmp($version, 'HTTP/', 5) !== 0
            || $version[6] !== '.'
            || ! ctype_digit($version[5])
            || ! ctype_digit($version[7])
        ) {
            throw new MessageParsingException('Invalid HTTP version: must match HTTP/DIGIT.DIGIT');
        }

        $headers = [];
        $offset = 0;
        $headerBodyLen = \strlen($headerBody);

        while ($offset < $headerBodyLen) {
            $nextCrLf = strpos($headerBody, "\r\n", $offset);

            if ($nextCrLf === false) {
                $line = substr($headerBody, $offset);
                $offset = $headerBodyLen;
            } else {
                $line = substr($headerBody, $offset, $nextCrLf - $offset);
                $offset = $nextCrLf + 2;
            }

            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                throw new MessageParsingException('Obsolete line folding (obs-fold) is not permitted in requests');
            }

            // RFC 9112 section 2.2: a bare CR within a field line must be rejected rather
            // than passed through as a literal byte in the parsed field value. Since the
            // line was extracted by scanning for a literal "\r\n", any "\r" still present
            // here is by definition a CR not immediately followed by LF.
            if (str_contains($line, "\r")) {
                throw new MessageParsingException('Bare CR is not permitted within a header field line');
            }

            $colonPos = strpos($line, ':');

            if ($colonPos === false) {
                throw new MessageParsingException("Malformed header field line: \"{$line}\"");
            }

            $rawFieldName = substr($line, 0, $colonPos);
            $fieldValue = trim(substr($line, $colonPos + 1));

            if ($rawFieldName !== rtrim($rawFieldName)) {
                throw new MessageParsingException("Whitespace before colon is not permitted in field name: \"{$rawFieldName}\"");
            }

            // RFC 9110 section 5.1: field names must conform to the token rule that is a visible ASCII.
            // Only (0x21–0x7E), excluding the delimiter set: ( ) < > @ , ; : \ " / [ ] ? = { }
            // The colon is structurally impossible here (extracted via strpos), but included
            // in the pattern for completeness. The whitespace check above already catches
            // trailing SP/HT, but the token rule is broader so null bytes, other controls,
            // and delimiter chars must also be rejected to prevent proxy differential attacks
            // where a front-end normalizes a malformed name that this parser silently accepted.
            if ($rawFieldName === '' || preg_match('/[^\x21-\x7E]|[()<>@,;:\\\\"\/\[\]?={}]/', $rawFieldName) === 1) {
                throw new MessageParsingException("Invalid characters in field name: \"{$rawFieldName}\"");
            }

            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $fieldValue) === 1) {
                throw new MessageParsingException("Invalid control character in header field value for: \"{$rawFieldName}\"");
            }

            $headers[strtolower($rawFieldName)][] = $fieldValue;
        }

        $protocolVersion = substr($version, 5);

        if ($protocolVersion === '1.1') {
            if (! isset($headers['host'])) {
                throw new MessageParsingException('HTTP/1.1 requests MUST include a Host header field');
            }
            if (\count($headers['host']) > 1) {
                throw new MessageParsingException('HTTP/1.1 requests MUST NOT contain more than one Host header field');
            }
            if (str_contains($headers['host'][0], ',')) {
                throw new MessageParsingException('Host header field must not contain a comma-separated list');
            }
        }

        $this->isChunked = false;
        $this->expectedBodyLength = 0;
        $forceClose = false;

        $hasTe = isset($headers['transfer-encoding']);
        $hasCl = isset($headers['content-length']);

        if ($hasTe && $hasCl) {
            $forceClose = true;
        }

        if ($hasTe) {
            $teVals = $headers['transfer-encoding'];
            $codings = [];

            foreach ($teVals as $value) {
                foreach (array_map('trim', explode(',', $value)) as $coding) {
                    if ($coding !== '') {
                        $codings[] = strtolower($coding);
                    }
                }
            }

            if ($codings === []) {
                throw new MessageParsingException('Malformed Transfer-Encoding chain');
            }

            $lastCoding = end($codings);

            if ($lastCoding !== 'chunked') {
                $knownCodings = ['chunked', 'compress', 'deflate', 'gzip', 'x-compress', 'x-gzip'];

                if (\in_array($lastCoding, $knownCodings, true)) {
                    throw new MessageParsingException('Transfer-Encoding chain must end with chunked');
                }

                throw new UnsupportedTransferCodingException("Unsupported transfer coding: \"{$lastCoding}\"");
            }

            // Note: The parser intentionally do NOT validate intermediate codings here.
            // Intermediaries or the application layer MAY process compressed codings
            // (e.g. gzip, chunked) internally. It only care that framing terminates in 'chunked'.

            $this->isChunked = true;

            // RFC 9112 section 6.1/6.3: once Transfer-Encoding is confirmed to resolve to
            // chunked framing, Content-Length is discarded outright
            unset($headers['content-length']);
            $this->expectedBodyLength = 0;
        } elseif ($hasCl) {
            $clVals = $headers['content-length'];

            if (\count($clVals) === 1 && ! str_contains($clVals[0], ',')) {
                $this->expectedBodyLength = $this->parseContentLengthValue($clVals[0]);
            } else {
                $clRaw = [];
                foreach ($clVals as $val) {
                    foreach (array_map('trim', explode(',', $val)) as $v) {
                        if ($v !== '') {
                            $clRaw[] = $v;
                        }
                    }
                }

                $uniqueCl = array_unique($clRaw);

                if (\count($uniqueCl) > 1) {
                    throw new MessageParsingException('Multiple conflicting Content-Length values');
                }

                $this->expectedBodyLength = $this->parseContentLengthValue((string) reset($uniqueCl));
            }
        }

        $serverParams = ['REMOTE_ADDR' => $this->connection->getRemoteAddress()];
        $this->currentRequest = new Request($method, $target, $headers, '', $protocolVersion, $serverParams);

        $connHeader = strtolower($this->currentRequest->getHeaderLine('connection'));
        if ($this->currentRequest->getProtocolVersion() === '1.0') {
            $this->willCloseConnection = $forceClose || ($connHeader !== 'keep-alive');
            $this->activeResponseVersion = '1.0';
        } else {
            $this->willCloseConnection = $forceClose || ($connHeader === 'close');
            $this->activeResponseVersion = '1.1';
        }

        if (! $this->streamingRequests && $this->expectedBodyLength > $this->maxBodySize) {
            throw new PayloadTooLargeException('Payload Too Large');
        }
    }

    /**
     * Validates and parses a single Content-Length field value.
     *
     * RFC 9110 section 8.6 requires Content-Length to be an unsigned string of decimal
     * digits so no leading "+"/"-" sign, no surrounding non-digit characters. A value
     * that doesn't match this grammar is an unrecoverable framing error per RFC 9112
     * section 6.3 and must never be silently coerced via a numeric cast: PHP's (int)
     * cast on "10abc" yields 10 and on "-5" yields -5, either of which would desync
     * this server's view of the body boundary from any front-end proxy in front of it.
     *
     * @throws MessageParsingException If the value isn't a valid unsigned decimal digit string.
     */
    private function parseContentLengthValue(string $value): int
    {
        $trimmed = trim($value);

        if ($trimmed === '' || ! ctype_digit($trimmed)) {
            throw new MessageParsingException("Invalid Content-Length value: \"{$value}\"");
        }

        return (int) $trimmed;
    }

    private function sendErrorAndClose(int $statusCode, string $reason): void
    {
        $this->connection->write("HTTP/1.1 {$statusCode} {$reason}\r\nConnection: close\r\n\r\n");
        $this->connection->close();
        $this->state = self::STATE_UPGRADED;
    }

    /**
     * Reclaims memory from the processed segment of the TCP buffer.
     */
    private function compactBuffer(): void
    {
        if ($this->bufferOffset === 0) {
            return;
        }

        $this->buffer = $this->bufferOffset < \strlen($this->buffer)
            ? substr($this->buffer, $this->bufferOffset)
            : '';

        $this->bufferOffset = 0;
    }

    /**
     * Converts internal lowercase headers (e.g., "content-type") to
     * standard HTTP/1.1 Title-Case wire format (e.g., "Content-Type").
     */
    private static function formatHeaderNameForWire(string $name): string
    {
        if (isset(self::$headerNameCache[$name])) {
            return self::$headerNameCache[$name];
        }

        if (\count(self::$headerNameCache) > self::MAX_HEADER_CACHE_SIZE) {
            self::$headerNameCache = [];
        }

        return self::$headerNameCache[$name] = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
    }
}
