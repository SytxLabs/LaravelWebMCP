<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/** An agent started a WebMCP tool call (before it runs). `arguments` is null unless config webmcp.events.include_arguments is on; then sensitive keys are masked. */
final readonly class WebMcpToolInvoked
{
    /**
     * @param string $kind tool | resource | generic-resource
     * @param string $mode session | bridge
     * @param array<string, mixed>|null $arguments
     */
    public function __construct(public ?Authenticatable $user, public string $server, public string $tool, public string $kind, public string $mode, public bool $consequential, public ?array $arguments = null)
    {
    }
}
