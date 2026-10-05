<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Facades;

use Illuminate\Support\Facades\Facade;
use SytxLabs\LaravelWebMcp\Testing\WebMcpFake;
use SytxLabs\LaravelWebMcp\WebMcpManager;

/**
 * @method static \SytxLabs\LaravelWebMcp\Servers\ServerDefinition server(string $slug, string $class, ?string $endpoint = null, \SytxLabs\LaravelWebMcp\Attributes\WebMcpMode|string|null $mode = null, ?string $prefix = null)
 * @method static void nonceUsing(\Closure|null $resolver)
 * @method static void flush(\Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static array<string, \SytxLabs\LaravelWebMcp\Servers\ServerDefinition> servers()
 * @method static \SytxLabs\LaravelWebMcp\Manifest\Manifest manifest(string $slug, ?\SytxLabs\LaravelWebMcp\Manifest\NameRegistry $names = null)
 * @method static array<string, \SytxLabs\LaravelWebMcp\Manifest\Manifest> manifests(array<int, string>|null $slugs = null, ?\SytxLabs\LaravelWebMcp\Manifest\NameRegistry $names = null)
 *
 * @see WebMcpManager
 */
class WebMcp extends Facade
{
    /**
     * Start recording WebMCP calls for assertions in your tests. Calls still run for real unless you
     * stub them with `$fake->respondWith('tool-name', ...)`.
     *
     *     $fake = WebMcp::fake();
     *     $this->actingAs($user)->postJson('/webmcp/shop/tools/search', [...]);
     *     $fake->assertToolInvoked('search');
     */
    public static function fake(): WebMcpFake
    {
        $manager = static::getFacadeRoot();

        $fake = new WebMcpFake($manager instanceof WebMcpManager ? $manager : app(WebMcpManager::class), app('events'));
        app()->instance(WebMcpFake::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return WebMcpManager::class;
    }
}
