<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Observability;

use SytxLabs\LaravelWebMcp\Support\Json;
use SytxLabs\LaravelWebMcp\Support\Settings;

/**
 * Masks sensitive argument values before they reach events or the audit log. A key is sensitive when it contains one of the configured fragments (case-insensitive), at any depth.
 */
final class ArgumentMasker
{
    public const string MASK = '***';

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function mask(array $arguments): array
    {
        return Json::assoc($this->walk($arguments, array_map(mb_strtolower(...), $this->settings->strings('observability.mask'))));
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $fragments
     *
     * @return array<array-key, mixed>
     */
    private function walk(array $value, array $fragments): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->sensitive($key, $fragments)) {
                $value[$key] = self::MASK;

                continue;
            }
            if (is_array($item)) {
                $value[$key] = $this->walk($item, $fragments);
            }
        }

        return $value;
    }

    /**
     * @param list<string> $fragments
     */
    private function sensitive(string $key, array $fragments): bool
    {
        $key = mb_strtolower($key);
        foreach ($fragments as $fragment) {
            if ($fragment !== '' && str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
