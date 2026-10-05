<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Manifest;

use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;

/** Effective #[WebMcp] settings after applying class > server > registration > config priority. */
final readonly class ResolvedOptions
{
    /** @param  list<string>  $exposedTo  Normalized, allowlist-checked origins. */
    public function __construct(public WebMcpMode $mode, public ?string $name, public array $exposedTo, public ?bool $confirm, public bool $untrusted, public bool $debugging, public ?string $variablePattern)
    {
    }
}
