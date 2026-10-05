<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Support;

use Illuminate\Contracts\Config\Repository;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;

final readonly class Settings
{
    public function __construct(private Repository $config)
    {
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->config->get('webmcp.'.$key, $default);

        return is_bool($value) ? $value : $default;
    }

    public function nullableBool(string $key): ?bool
    {
        $value = $this->config->get('webmcp.'.$key);

        return is_bool($value) ? $value : null;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->config->get('webmcp.'.$key, $default);

        return is_int($value) ? $value : $default;
    }

    public function string(string $key, string $default): string
    {
        $value = $this->config->get('webmcp.'.$key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->config->get('webmcp.'.$key, []);

        return !is_array($value) ? [] : array_values(array_filter($value, is_string(...)));
    }

    /**
     * @return array<string, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->config->get('webmcp.'.$key, []);

        return !is_array($value) ? [] : collect($value)->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all();
    }

    public function defaultMode(): WebMcpMode
    {
        $mode = WebMcpMode::tryFrom($this->string('defaults.mode', WebMcpMode::Session->value));

        return $mode === null || $mode === WebMcpMode::Inherit ? WebMcpMode::Session : $mode;
    }
}
