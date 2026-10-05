<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Support;

/** Small helpers for decoded JSON (arrays with unknown key types). */
final class Json
{
    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function assoc(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}
