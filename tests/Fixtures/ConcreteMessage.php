<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hibla\HttpServer\Message\AbstractMessage;

/**
 * Concrete implementation of AbstractMessage purely for testing base logic.
 */
class ConcreteMessage extends AbstractMessage
{
    public function __construct(array $headers = [], string $body = '', string $protocolVersion = '1.1')
    {
        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $body;
        $this->protocolVersion = $protocolVersion;
    }
}
