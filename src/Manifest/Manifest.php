<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Manifest;

final readonly class Manifest
{
    /** Bump when this class or ToolDefinition changes shape: it is part of the manifest cache key. */
    public const int CACHE_VERSION = 1;

    /**
     * @param list<ToolDefinition> $tools
     * @param list<Exclusion> $exclusions
     * @param bool $cacheable false when a class opted out with #[WebMcp(cache: false)]
     */
    public function __construct(public string $server, public array $tools, public array $exclusions = [], public bool $cacheable = true)
    {
    }

    public function tool(string $name): ?ToolDefinition
    {
        foreach ($this->tools as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function toolNames(): array
    {
        return array_map(static fn (ToolDefinition $tool): string => $tool->name, $this->tools);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['server' => $this->server, 'tools' => array_map(static fn (ToolDefinition $tool): array => $tool->toArray(), $this->tools)];
    }
}
