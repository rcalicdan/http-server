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

    private bool $isChunked = false;

    private bool $willCloseConnection = false;

    private int $state = self::STATE_HEADERS;

    private int $expectedBodyLength = 0;

    private int $bytesRead = 0;

    private int $currentChunkSize = 0;

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
     * @param int $maxBodySize Limit for request body buffering in bytes (Default: 10MB)
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
     * @return void
     */
    public function writeResponse(Response $response): void
    {
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
     * The size cap is enforced on BOTH the "still waiting for the terminator" branch
     * and the "terminator found" branch below. Checking only the former (the original
     * bug) let an oversized header block bypass the limit entirely whenever it happened
     * to arrive complete, terminator included, within a single handleData() call.
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
     *
     * pushBodyData() now returns a bool: if it returns false, an error response
     * has already been written and the connection closed (state was set to
     * STATE_UPGRADED). This method must bail out immediately in that case
     * rather than proceeding to finalizeRequest(), which previously could fire
     * the onRequest callback with a truncated body on an already-closed connection.
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
     * Uses strpos over explode for chunk-extension detection to avoid an array allocation
     * on every chunk in the common case where no extension is present.
     *
     * The "no CRLF found yet" branch now enforces MAX_CHUNK_LINE_SIZE, mirroring the
     * header-size cap — without it, an unterminated chunk-size line could grow the
     * receive buffer without bound while waiting for a line terminator that never arrives.
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

        $this->currentChunkSize = (int) hexdec($hex);

        if ($this->currentChunkSize === 0) {
            $this->state = self::STATE_CHUNK_TRAILER;

            return $this->parseChunkTrailerPhase();
        }

        $this->state = self::STATE_CHUNK_DATA;

        return true;
    }

    /**
     * As with parseBodyLengthPhase(), this must bail out immediately — without
     * advancing $bufferOffset or transitioning $state back to STATE_CHUNK_SIZE —
     * when pushBodyData() signals that an error response has already been sent
     * and the connection closed. The original code unconditionally overwrote
     * the STATE_UPGRADED flag right after it was set, letting parsing continue
     * on a connection that was supposed to be dead.
     */
    private function parseChunkDataPhase(): bool
    {
        $available = \strlen($this->buffer) - $this->bufferOffset;

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
     * After the terminal "0\r\n" chunk, the wire format consists of zero or more
     * trailer fields followed by an empty line (CRLF). This method skips the
     * trailers entirely (as permitted by the RFC) and advances the buffer past
     * the terminating CRLF boundary before finalizing the request.
     *
     * The fast path (no trailer fields) detects the bare terminating CRLF via direct
     * character comparison against the offset pointer, avoiding a strpos scan when
     * trailers are absent — which is the common case.
     *
     * The "no terminator found yet" branch enforces MAX_HEADER_SIZE as a stand-in
     * trailer-section bound, for the same reason the header phase needs one: an
     * unterminated trailer section is otherwise unbounded.
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
     *              (state is now STATE_UPGRADED) — callers MUST stop processing
     *              immediately when this returns false rather than continuing to
     *              advance the buffer or transition state.
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
        // Defensive guard: if a prior error already closed the connection
        // (state === STATE_UPGRADED) within this same handleData() call,
        // never fire the onRequest callback on a dead connection.
        if ($this->currentRequest === null || $this->state === self::STATE_UPGRADED) {
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

        $this->state = self::STATE_HEADERS;
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
     * - section 2.2: Rejects a bare CR (a CR not immediately followed by LF) within the request-line
     *   or any header field line, instead of silently passing it through as a literal byte.
     * - section 2.3: Validates HTTP version format and case sensitivity (e.g., "HTTP/1.1").
     * - section 3.2: Enforces that the Host header is mandatory and singular for HTTP/1.1 requests.
     * - section 5.1: Rejects any whitespace between the field name and the colon.
     * - section 5.1: Rejects a field line with no colon at all, instead of silently skipping it.
     * - section 5.2: Rejects Obsolete Line Folding (obs-fold) to prevent request smuggling.
     * - section 6.1: Parses Transfer-Encoding as a list, treating the request as chunked only if "chunked" is the final coding.
     * - section 6.1 / 6.3: Transfer-Encoding always overrides Content-Length whenever it is present at
     *   all — not only when it resolves to "chunked" — so Content-Length is never even inspected once
     *   a Transfer-Encoding header is found. A Transfer-Encoding that doesn't resolve to "chunked" as
     *   its final coding is rejected outright (RFC 9110 section 10.1.4 suggests 501) rather than being
     *   silently ignored in favor of Content-Length-based framing, which is a request-smuggling vector.
     * - section 6.2 / RFC 9110 section 8.6: Rejects multiple conflicting Content-Length values, and
     *   validates that any Content-Length value is a well-formed, unsigned decimal digit string —
     *   never coerced via a permissive numeric cast that would silently accept "10abc" or "-5".
     * - section 6.3: Removes Content-Length entirely if Transfer-Encoding is present, preventing conflicting body length calculations.
     *
     * @param string $rawHeaders The raw HTTP header string including the request line.
     *
     * @throws MessageParsingException If any structural validation fails.
     * @throws UnsupportedTransferCodingException If Transfer-Encoding names a coding this server
     *                                            doesn't implement, or doesn't end in "chunked".
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

            // Fast loop fallback: If no further CRLF is found, process the final header line.
            if ($nextCrLf === false) {
                $line = substr($headerBody, $offset);
                $offset = $headerBodyLen; // Triggers loop exit on next iteration.
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
        }

        $this->isChunked = false;
        $this->expectedBodyLength = 0;

        if (isset($headers['transfer-encoding'])) {
            $teVals = $headers['transfer-encoding'];

            if (\count($teVals) === 1 && ! str_contains($teVals[0], ',')) {
                $coding = strtolower(trim($teVals[0]));

                if ($coding !== 'chunked') {
                    throw new UnsupportedTransferCodingException("Unsupported transfer coding: \"{$coding}\"");
                }

                $this->isChunked = true;
            } else {
                $codings = [];
                foreach ($teVals as $value) {
                    foreach (array_map('trim', explode(',', $value)) as $coding) {
                        if ($coding !== '') {
                            $codings[] = strtolower($coding);
                        }
                    }
                }

                if ($codings === [] || end($codings) !== 'chunked') {
                    throw new UnsupportedTransferCodingException('Unsupported or malformed Transfer-Encoding chain');
                }

                $this->isChunked = true;
            }

            // RFC 9112 section 6.1/6.3: once Transfer-Encoding is confirmed to resolve to
            // chunked framing, Content-Length is discarded outright — its value is never
            // read, let alone validated. This also means a malformed or oversized
            // Content-Length sent alongside a valid Transfer-Encoding never reaches the
            // validation logic below; it's simply irrelevant.
            unset($headers['content-length']);
            $this->expectedBodyLength = 0;
        } elseif (isset($headers['content-length'])) {
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
            $this->willCloseConnection = ($connHeader !== 'keep-alive');
            $this->activeResponseVersion = '1.0';
        } else {
            $this->willCloseConnection = ($connHeader === 'close');
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
     *
     * Executed exactly once per TCP read event to cap substr() allocations,
     * maintaining high performance during pipelined request storms.
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
