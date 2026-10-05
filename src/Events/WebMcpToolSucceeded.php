<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/** A WebMCP tool call finished without an error result. */
final readonly class WebMcpToolSucceeded
{
    /** @param  array<string, mixed>|null  $arguments */
    public function __construct(public ?Authenticatable $user, public string $server, public string $tool, public string $kind, public string $mode, public bool $consequential, public float $durationMs, public ?array $arguments = null)
    {
    }
}
