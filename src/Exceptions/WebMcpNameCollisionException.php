<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Exceptions;

/** Two exposed primitives resolve to the same WebMCP tool name (registerTool rejects duplicates). */
class WebMcpNameCollisionException extends InvalidWebMcpConfigurationException
{
    public static function between(string $name, string $first, string $second): self
    {
        return new self("WebMCP tool name [{$name}] is used twice: by [{$first}] and by [{$second}]. ".'Set a unique #[WebMcp(name: ...)] or a server prefix.');
    }
}
