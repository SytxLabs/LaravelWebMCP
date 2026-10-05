<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/** A manifest was built (or served from the cache) for a user. */
final readonly class WebMcpManifestBuilt
{
    public function __construct(public ?Authenticatable $user, public string $server, public int $toolCount, public float $durationMs, public bool $cacheHit)
    {
    }
}
