<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Mcp\Server;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Cache\ManifestCache;
use SytxLabs\LaravelWebMcp\Manifest\Manifest;
use SytxLabs\LaravelWebMcp\Manifest\ManifestBuilder;
use SytxLabs\LaravelWebMcp\Manifest\NameRegistry;
use SytxLabs\LaravelWebMcp\Servers\ServerDefinition;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;
use SytxLabs\LaravelWebMcp\View\NonceResolver;

/**
 * Entry point behind the WebMcp facade. Stateless apart from the (static) server registry.
 */
readonly class WebMcpManager
{
    public function __construct(private ServerRegistry $registry, private ManifestBuilder $builder)
    {
    }

    /**
     * Register a laravel/mcp server for WebMCP exposure.
     *
     * @param class-string<Server> $class
     */
    public function server(string $slug, string $class, ?string $endpoint = null, string|WebMcpMode|null $mode = null, ?string $prefix = null): ServerDefinition
    {
        return $this->registry->register($slug, $class, $endpoint, $mode, $prefix);
    }

    /**
     * Set the CSP nonce source for the manifest and runtime tags (default: Vite::cspNonce()). Call it once at boot; it is application wiring, not request state.
     *
     * @param Closure(): (string|null)|null $resolver
     */
    public function nonceUsing(?Closure $resolver): void
    {
        NonceResolver::using($resolver);
    }

    /**
     * Drop the cached manifests of one user, or of everybody when no user is given. Login, logout and password resets do this on their own; call it when a role or permission changes what a user may see.
     */
    public function flush(?Authenticatable $user = null): void
    {
        app(ManifestCache::class)->flush($user);
    }

    /**
     * @return array<string, ServerDefinition>
     */
    public function servers(): array
    {
        return $this->registry->all();
    }

    /**
     * Manifest of one server for the current request/user.
     */
    public function manifest(string $slug, ?NameRegistry $names = null): Manifest
    {
        return $this->builder->build($this->registry->get($slug), $names);
    }

    /**
     * Manifests of several servers; tool names must be unique across all of them (one page = one document).
     *
     * @param list<string>|null $slugs null = all registered servers
     * @param NameRegistry|null $names null = the request's registry (shared with Livewire actions and forms)
     *
     * @return array<string, Manifest>
     */
    public function manifests(?array $slugs = null, ?NameRegistry $names = null): array
    {
        $names ??= app(NameRegistry::class);
        $manifests = [];
        foreach ($slugs ?? array_keys($this->registry->all()) as $slug) {
            $manifests[$slug] = $this->manifest($slug, $names);
        }

        return $manifests;
    }
}
