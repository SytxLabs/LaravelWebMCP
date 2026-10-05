<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Cache;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use JsonException;
use Laravel\Mcp\Enums\CacheScope;
use Laravel\Mcp\Server\Attributes\Cacheable;
use ReflectionClass;
use ReflectionException;
use SytxLabs\LaravelWebMcp\Manifest\Manifest;
use SytxLabs\LaravelWebMcp\Manifest\ToolDefinition;
use SytxLabs\LaravelWebMcp\Servers\ServerDefinition;
use SytxLabs\LaravelWebMcp\Support\AttributeResolver;
use SytxLabs\LaravelWebMcp\Support\Settings;

/**
 * Manifest cache per server and auth context (config webmcp.cache, off by default).
 *
 * Key: server, guard, user (or "guest"), locale, user/global epoch and a hash of the configuration and the server class.
 * Login, Logout, PasswordReset and device logouts bump the user's epoch, WebMcp::flush() bumps the user's or the global one, so a changed auth state never sees an old manifest.
 *
 * `shouldRegister()` may look at more than the user (IP, feature flags, time). Such tools must opt out with #[WebMcp(cache: false)]; the manifest of a server that contains one is never cached.
 *
 * laravel/mcp's #[Cacheable] is honored as an upper bound for the TTL (server, tool or resource class).
 * A server marked `#[Cacheable(scope: CacheScope::Public)]` AND `#[WebMcp(sharedCache: true)]` shares one manifest between all users; without both, entries are always per user.
 */
final class ManifestCache
{
    private const string GLOBAL_EPOCH = 'webmcp:epoch:global';

    public function __construct(private readonly CacheFactory $cache, private readonly AuthFactory $auth, private readonly Settings $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('cache.enabled', false) && $this->settings->int('cache.ttl', 300) > 0;
    }

    /** @param  Closure(): Manifest  $compute */
    public function remember(ServerDefinition $server, Closure $compute, bool &$hit): Manifest
    {
        $hit = false;
        if (!$this->enabled()) {
            return $compute();
        }
        $store = $this->store();
        $key = $this->key($server, $store);
        $cached = $store->get($key);
        if ($cached instanceof Manifest) {
            $hit = true;

            return $cached;
        }
        $manifest = $compute();
        if ($manifest->cacheable) {
            $store->put($key, $manifest, $this->ttl($server, $manifest));
        }

        return $manifest;
    }

    /**
     * Invalidate the cached manifests of one user, or of everybody when no user is given.
     */
    public function flush(?Authenticatable $user = null): void
    {
        $store = $this->store();
        $key = $user === null ? self::GLOBAL_EPOCH : $this->userEpochKey($user->getAuthIdentifier());

        $store->forever($key, $this->epoch($store, $key) + 1);
    }

    private function key(ServerDefinition $server, Repository $store): string
    {
        $shared = $this->shared($server);
        $guard = $this->auth->guard();
        $id = $guard->id();
        $identity = $shared ? 'shared' : ((is_int($id) || is_string($id)) ? 'user:'.$id : 'guest');
        $userEpoch = $shared || !(is_int($id) || is_string($id)) ? 0 : $this->epoch($store, $this->userEpochKey($id));

        return implode(':', [
            'webmcp:manifest',
            Manifest::CACHE_VERSION,
            $server->slug,
            $this->auth->getDefaultDriver(),
            $identity,
            $this->epoch($store, self::GLOBAL_EPOCH).'.'.$userEpoch,
            app()->getLocale(),
            $this->fingerprint($server),
        ]);
    }

    /**
     * Changes whenever the package configuration or the server class file changes (deploys).
     */
    private function fingerprint(ServerDefinition $server): string
    {
        try {
            $file = (new ReflectionClass($server->class))->getFileName();
        } catch (ReflectionException) {
            $file = false;
        }
        $array = [
            $this->settings->array('cache'),
            $this->settings->array('limits'),
            $this->settings->array('resources'),
            $this->settings->array('features'),
            $this->settings->array('defaults'),
            $this->settings->strings('allowed_origins'),
            $server->class,
            $server->mode?->value,
            $server->prefix,
            $file !== false && is_file($file) ? filemtime($file) : 0,
        ];
        try {
            $encode = json_encode($array, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $encode = serialize($array);
        }

        return substr(hash('xxh128', $encode), 0, 16);
    }

    private function shared(ServerDefinition $server): bool
    {
        $attribute = AttributeResolver::webMcp($server->class);
        $cacheable = AttributeResolver::find($server->class, Cacheable::class);

        return $attribute !== null && $attribute->sharedCache && $cacheable instanceof Cacheable && $cacheable->scope === CacheScope::Public;
    }

    private function ttl(ServerDefinition $server, Manifest $manifest): int
    {
        $ttl = max(1, $this->settings->int('cache.ttl', 300));
        foreach ([$server->class, ...array_map(static fn (ToolDefinition $tool): string => $tool->source, $manifest->tools)] as $class) {
            $hint = AttributeResolver::find($class, Cacheable::class);
            if ($hint instanceof Cacheable && $hint->ttlMs > 0) {
                $ttl = min($ttl, max(1, (int) ceil($hint->ttlMs / 1000)));
            }
        }

        return $ttl;
    }

    private function store(): Repository
    {
        $name = $this->settings->string('cache.store', '');

        return $this->cache->store($name === '' ? null : $name);
    }

    private function epoch(Repository $store, string $key): int
    {
        $value = $store->get($key, 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function userEpochKey(mixed $id): string
    {
        return 'webmcp:epoch:user:'.(is_int($id) || is_string($id) ? $id : 'unknown');
    }
}
