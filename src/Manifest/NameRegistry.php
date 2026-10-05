<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Manifest;

use SytxLabs\LaravelWebMcp\Exceptions\WebMcpNameCollisionException;

/**
 * Tracks WebMCP tool names of one page (one request); names must be unique per document. Bound as a scoped service, so nothing leaks between requests (Octane).
 */
final class NameRegistry
{
    /** @var array<string, string> */
    private array $claimed = [];

    public function claim(string $name, string $source): void
    {
        if (isset($this->claimed[$name]) && $this->claimed[$name] !== $source) {
            throw WebMcpNameCollisionException::between($name, $this->claimed[$name], $source);
        }
        $this->claimed[$name] = $source;
    }
}
