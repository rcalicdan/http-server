<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Internals;

use Hibla\HttpServer\Message\Response;

/**
 * @internal
 * 
 * Represents a sequence placeholder for HTTP 1.1 pipelining.
 */
final class Http11PipelineItem
{
    public bool $isEarly = false;

    public bool $isReady = false;

    public ?string $data = null;

    public ?Response $response = null;
}
