<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Exceptions;

/** #[WebMcp] was placed on something WebMCP cannot represent (e.g. a Prompt). */
class InvalidWebMcpTargetException extends InvalidWebMcpConfigurationException
{
    public static function prompt(string $class): self
    {
        return new self("#[WebMcp] on prompt [{$class}] is not allowed: WebMCP has no counterpart for MCP prompts. Remove the attribute.");
    }
}
