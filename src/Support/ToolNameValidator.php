<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Support;

final class ToolNameValidator
{
    public const int MAX_LENGTH = 128;

    public static function isValid(string $name): bool
    {
        return preg_match('/\A[A-Za-z0-9_.\-]{1,'.self::MAX_LENGTH.'}\z/', $name) === 1;
    }

    public static function isValidPrefix(string $prefix): bool
    {
        return preg_match('/\A[A-Za-z0-9_.\-]{1,64}\z/', $prefix) === 1;
    }
}
