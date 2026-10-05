<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A WebMCP tool call ended in an error. `reason` is a short machine-readable code: tool-error, invalid-arguments, forbidden, confirmation-required, exception, blocked (Bridge guard).
 */
final readonly class WebMcpToolFailed
{
    /** @param  array<string, mixed>|null  $arguments */
    public function __construct(public ?Authenticatable $user, public string $server, public string $tool, public string $kind, public string $mode, public bool $consequential, public float $durationMs, public string $reason, public ?array $arguments = null)
    {
    }
}
