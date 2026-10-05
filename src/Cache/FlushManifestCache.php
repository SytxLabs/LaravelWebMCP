<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Cache;

use Illuminate\Contracts\Auth\Authenticatable;

/** Listener for Login, Logout, PasswordReset, CurrentDeviceLogout and OtherDeviceLogout: whoever's auth state changed must not be served a manifest built for the old state. */
final readonly class FlushManifestCache
{
    public function __construct(private ManifestCache $cache)
    {
    }

    public function handle(object $event): void
    {
        if (!$this->cache->enabled()) {
            return;
        }
        $user = $event->user ?? null;
        $this->cache->flush($user instanceof Authenticatable ? $user : null);
    }
}
