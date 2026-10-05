<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Manifest;

/** Why a primitive is not exposed. Server-side only (webmcp:list), never serialized into the page. */
final readonly class Exclusion
{
    /** @param  class-string  $source */
    public function __construct(public string $server, public string $source, public ExclusionReason $reason, public ?string $detail = null)
    {
    }
}
