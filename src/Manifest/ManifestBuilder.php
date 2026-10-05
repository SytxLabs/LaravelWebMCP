<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Manifest;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use SytxLabs\LaravelWebMcp\Cache\ManifestCache;
use SytxLabs\LaravelWebMcp\Events\WebMcpManifestBuilt;
use SytxLabs\LaravelWebMcp\Servers\ServerDefinition;

/**
 * Builds the WebMCP manifest of one laravel/mcp server for the current request/user: compiles it (or takes it from the manifest cache),
 * keeps the page-wide tool name registry consistent and announces it with WebMcpManifestBuilt.
 */
final readonly class ManifestBuilder
{
    public function __construct(private Container $container, private ManifestCompiler $compiler, private ManifestCache $cache, private AuthFactory $auth)
    {
    }

    public function build(ServerDefinition $definition, ?NameRegistry $names = null): Manifest
    {
        $names ??= $this->container->make(NameRegistry::class);
        $started = hrtime(true);
        $hit = false;
        $manifest = $this->cache->remember($definition, fn (): Manifest => $this->compiler->compile($definition, $names), $hit);
        if ($hit) {
            foreach ($manifest->tools as $tool) {
                $names->claim($tool->name, ToolDefinition::claimLabel($definition->slug, $tool->source, $tool->kind));
            }
        }
        $this->container->make(Dispatcher::class)->dispatch(new WebMcpManifestBuilt($this->auth->guard()->user(), $definition->slug, count($manifest->tools), round((hrtime(true) - $started) / 1_000_000, 3), $hit));

        return $manifest;
    }
}
