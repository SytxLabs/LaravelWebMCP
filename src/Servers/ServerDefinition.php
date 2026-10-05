<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Servers;

use Laravel\Mcp\Server;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;

final readonly class ServerDefinition
{
    /**
     * @param class-string<Server> $class
     * @param string|null $endpoint URI of the Mcp::web() route (Bridge mode).
     * @param WebMcpMode|null $mode Server default; null = config default.
     */
    public function __construct(public string $slug, public string $class, public ?string $endpoint = null, public ?WebMcpMode $mode = null, public ?string $prefix = null, public bool $enabled = true)
    {
    }
}
