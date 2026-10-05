<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Observability;

use Illuminate\Contracts\Auth\Authenticatable;
use SytxLabs\LaravelWebMcp\Manifest\ToolDefinition;

/**
 * One running tool call. Finish it exactly once with succeed() or fail(); further calls are ignored.
 */
final class Invocation
{
    private bool $finished = false;

    /**
     * @param array<string, mixed>|null $eventArguments masked, only when events.include_arguments is on
     * @param array<string, mixed>|null $auditArguments masked, only when audit.include_arguments is on
     */
    public function __construct(private readonly Recorder $recorder, public readonly ToolDefinition $tool, public readonly ?Authenticatable $user, public readonly bool $consequential, public readonly int $startedAt, public readonly ?array $eventArguments, public readonly ?array $auditArguments)
    {
    }

    public function succeed(): void
    {
        $this->finish(null);
    }

    public function fail(string $reason): void
    {
        $this->finish($reason);
    }

    private function finish(?string $reason): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        $this->recorder->finish($this, $reason);
    }
}
