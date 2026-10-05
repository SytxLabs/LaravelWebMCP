<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Exceptions;

/** The tool is unknown, not exposed to the current user, or not served over this route (HTTP 404). */
class WebMcpToolNotFoundException extends WebMcpException
{
    public static function named(string $name): self
    {
        return new self("WebMCP tool [{$name}] is not available.");
    }
}
