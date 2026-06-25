<?php

declare(strict_types=1);

use Hibla\HttpServer\HttpServer;

/**
 * Helper to read private properties from the HttpServer instance.
 */
function getServerProperty(HttpServer $server, string $property): mixed
{
    $reflection = new ReflectionClass($server);

    return $reflection->getProperty($property)->getValue($server);
}
