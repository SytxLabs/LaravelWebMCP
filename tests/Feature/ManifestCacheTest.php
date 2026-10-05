<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Event;
use SytxLabs\LaravelWebMcp\Events\WebMcpManifestBuilt;
use SytxLabs\LaravelWebMcp\Exceptions\WebMcpNameCollisionException;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Manifest\NameRegistry;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;

beforeEach(function () {
    config(['webmcp.cache.enabled' => true, 'webmcp.cache.ttl' => 300, 'cache.default' => 'array']);
    Fixtures\CountingTool::$registered = 0;
    Fixtures\UncacheableTool::$registered = 0;
    Cache::flush();
    WebMcp::server('cache', Fixtures\CacheServer::class);
});

function user(int $id): GenericUser
{
    return new GenericUser(['id' => $id]);
}

it('compiles once and serves the next manifest from the cache', function () {
    $first = WebMcp::manifest('cache');
    $second = WebMcp::manifest('cache');

    expect(Fixtures\CountingTool::$registered)->toBe(1)
        ->and($second->toolNames())->toBe($first->toolNames());
});

it('reports cache hits in the ManifestBuilt event', function () {
    Event::fake([WebMcpManifestBuilt::class]);

    WebMcp::manifest('cache');
    WebMcp::manifest('cache');

    $hits = [];
    Event::assertDispatched(WebMcpManifestBuilt::class, function (WebMcpManifestBuilt $e) use (&$hits) {
        $hits[] = $e->cacheHit;

        return true;
    });

    expect($hits)->toBe([false, true]);
});

it('compiles every time when the cache is off (default)', function () {
    config(['webmcp.cache.enabled' => false]);

    WebMcp::manifest('cache');
    WebMcp::manifest('cache');

    expect(Fixtures\CountingTool::$registered)->toBe(2);
});

it('keys the cache per user: a guest manifest is never served to a logged-in user', function () {
    expect(WebMcp::manifest('cache')->toolNames())->not->toContain('authed-tool');

    $this->actingAs(user(1));

    expect(WebMcp::manifest('cache')->toolNames())->toContain('authed-tool');
    expect(Fixtures\CountingTool::$registered)->toBe(2); // guest and user 1 compiled separately

    $this->actingAs(user(1));
    WebMcp::manifest('cache');

    expect(Fixtures\CountingTool::$registered)->toBe(2); // user 1 again: cache hit

    $this->actingAs(user(2));
    WebMcp::manifest('cache');

    expect(Fixtures\CountingTool::$registered)->toBe(3); // user 2: own entry
});

it('keys the cache per locale', function () {
    WebMcp::manifest('cache');
    app()->setLocale('de');
    WebMcp::manifest('cache');
    WebMcp::manifest('cache');

    expect(Fixtures\CountingTool::$registered)->toBe(2);
});

it('invalidates the user on Login and Logout', function () {
    $user = user(1);
    $this->actingAs($user);

    WebMcp::manifest('cache');
    event(new Login('web', $user, false));
    WebMcp::manifest('cache');

    expect(Fixtures\CountingTool::$registered)->toBe(2);

    event(new Logout('web', $user));
    WebMcp::manifest('cache');

    expect(Fixtures\CountingTool::$registered)->toBe(3);
});

it('invalidates only the affected user on login', function () {
    $this->actingAs(user(1));
    WebMcp::manifest('cache');
    $this->actingAs(user(2));
    WebMcp::manifest('cache');
    expect(Fixtures\CountingTool::$registered)->toBe(2);

    event(new Login('web', user(1), false));

    $this->actingAs(user(2));
    WebMcp::manifest('cache');
    expect(Fixtures\CountingTool::$registered)->toBe(2); // user 2 untouched

    $this->actingAs(user(1));
    WebMcp::manifest('cache');
    expect(Fixtures\CountingTool::$registered)->toBe(3);
});

it('can be flushed for one user or for everybody', function () {
    $this->actingAs(user(1));
    WebMcp::manifest('cache');
    $this->actingAs(user(2));
    WebMcp::manifest('cache');

    WebMcp::flush(user(1));
    $this->actingAs(user(2));
    WebMcp::manifest('cache');
    expect(Fixtures\CountingTool::$registered)->toBe(2);

    WebMcp::flush();
    $this->actingAs(user(2));
    WebMcp::manifest('cache');
    expect(Fixtures\CountingTool::$registered)->toBe(3);
});

it('never caches a server that contains a #[WebMcp(cache: false)] class', function () {
    WebMcp::server('uncacheable', Fixtures\UncacheableServer::class);

    WebMcp::manifest('uncacheable');
    WebMcp::manifest('uncacheable');

    expect(Fixtures\UncacheableTool::$registered)->toBe(2)->and(Fixtures\CountingTool::$registered)->toBe(2);
});

it('honors a #[Cacheable] ttl from laravel/mcp as an upper bound', function () {
    WebMcp::server('short', Fixtures\ShortCacheServer::class);

    WebMcp::manifest('short');
    $this->travel(1)->seconds();
    WebMcp::manifest('short');

    expect(Fixtures\CountingTool::$registered)->toBe(1);

    $this->travel(3)->seconds(); // past the 2 s hint although the config ttl is 300 s
    WebMcp::manifest('short');

    expect(Fixtures\CountingTool::$registered)->toBe(2);
});

it('applies the configured ttl', function () {
    config(['webmcp.cache.ttl' => 10]);

    WebMcp::manifest('cache');
    $this->travel(11)->seconds();
    WebMcp::manifest('cache');

    expect(Fixtures\CountingTool::$registered)->toBe(2);
});

it('shares one entry between users only for a public #[Cacheable] server with sharedCache', function () {
    WebMcp::server('shared', Fixtures\SharedCacheServer::class);

    $this->actingAs(user(1));
    WebMcp::manifest('shared');
    $this->actingAs(user(2));
    WebMcp::manifest('shared');

    expect(Fixtures\CountingTool::$registered)->toBe(1);

    // the ordinary server stays per user (separate request: its own tool-name registry)
    app()->forgetInstance(NameRegistry::class);
    $this->actingAs(user(1));
    WebMcp::manifest('cache');
    $this->actingAs(user(2));
    WebMcp::manifest('cache');

    expect(Fixtures\CountingTool::$registered)->toBe(3);
});

it('recompiles when the package configuration changes', function () {
    WebMcp::manifest('cache');
    config(['webmcp.limits.max_title' => 12]);
    WebMcp::manifest('cache');

    expect(Fixtures\CountingTool::$registered)->toBe(2);
});

it('still detects name collisions between servers when one comes from the cache', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);
    WebMcp::server('weather', Fixtures\WeatherOnlyServer::class);

    WebMcp::manifest('basic'); // cached
    app()->forgetInstance(NameRegistry::class);

    WebMcp::manifests(['basic', 'weather']);
})->throws(WebMcpNameCollisionException::class);

it('can use a dedicated cache store', function () {
    config(['cache.stores.webmcp' => ['driver' => 'array'], 'webmcp.cache.store' => 'webmcp']);

    WebMcp::manifest('cache');
    Cache::store('array')->flush(); // default store emptied: the dedicated one still holds the entry
    WebMcp::manifest('cache');

    expect(Fixtures\CountingTool::$registered)->toBe(1);
});
