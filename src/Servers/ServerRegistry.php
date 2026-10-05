<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Servers;

use InvalidArgumentException;
use Laravel\Mcp\Server;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Exceptions\UnknownWebMcpServerException;

/**
 * Static server configuration (config + fluent registration). Holds no request state. Mcp::web() does not expose its server class, so servers must be registered explicitly.
 */
final class ServerRegistry
{
    /** @var array<string, ServerDefinition> */
    private array $servers = [];

    /**
     * @param class-string<Server> $class
     */
    public function register(string $slug, string $class, ?string $endpoint = null, string|WebMcpMode|null $mode = null, ?string $prefix = null, bool $enabled = true): ServerDefinition
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $slug) !== 1) {
            throw new InvalidArgumentException("Invalid WebMCP server slug [{$slug}]: use lowercase a-z 0-9 _ -.");
        }
        if (!is_subclass_of($class, Server::class)) {
            throw new InvalidArgumentException("[{$class}] must extend ".Server::class.'.');
        }
        if (is_string($mode)) {
            $mode = WebMcpMode::from($mode);
        }

        return $this->servers[$slug] = new ServerDefinition($slug, $class, $endpoint, $mode, $prefix, $enabled);
    }

    /**
     * @param array<string, mixed> $servers slug => class-string|array{class, endpoint?, mode?, prefix?, enabled?}
     */
    public function registerMany(array $servers): void
    {
        foreach ($servers as $slug => $definition) {
            $class = is_array($definition) ? ($definition['class'] ?? null) : $definition;
            if (!is_string($class) || !is_subclass_of($class, Server::class)) {
                throw new InvalidArgumentException("WebMCP server [{$slug}] must be a ".Server::class.' class name.');
            }
            if (!is_array($definition)) {
                $this->register($slug, $class);

                continue;
            }

            $endpoint = $definition['endpoint'] ?? null;
            $mode = $definition['mode'] ?? null;
            $prefix = $definition['prefix'] ?? null;
            $this->register($slug, $class, is_string($endpoint) ? $endpoint : null, $mode instanceof WebMcpMode || is_string($mode) ? $mode : null, is_string($prefix) ? $prefix : null, ($definition['enabled'] ?? true) !== false);
        }
    }

    public function get(string $slug): ServerDefinition
    {
        return $this->servers[$slug] ?? throw UnknownWebMcpServerException::slug($slug);
    }

    /**
     * Find a server by slug or by its laravel/mcp server class (first registration wins).
     */
    public function resolve(string $slugOrClass): ServerDefinition
    {
        if (isset($this->servers[$slugOrClass])) {
            return $this->servers[$slugOrClass];
        }
        foreach ($this->servers as $definition) {
            if (ltrim($definition->class, '\\') === ltrim($slugOrClass, '\\')) {
                return $definition;
            }
        }
        throw UnknownWebMcpServerException::slug($slugOrClass);
    }

    public function has(string $slug): bool
    {
        return isset($this->servers[$slug]);
    }

    /**
     * @return array<string, ServerDefinition>
     */
    public function all(): array
    {
        return $this->servers;
    }
}
