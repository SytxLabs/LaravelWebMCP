<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Execution;

/** Final JSON-RPC response of an in-process call. Notifications are counted, never returned. */
final readonly class McpOutcome
{
    /**
     * @param array<string, mixed>|null $result
     * @param array<string, mixed>|null $error
     */
    public function __construct(public ?array $result, public ?array $error, public int $notifications = 0)
    {
    }

    public function failed(): bool
    {
        return $this->result === null;
    }
}
