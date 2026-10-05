<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Exceptions;

class UnknownWebMcpServerException extends WebMcpException
{
    public static function slug(string $slug): self
    {
        return new self("WebMCP server [{$slug}] is not registered.");
    }
}
