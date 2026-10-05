<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Support;

use ReflectionClass;
use ReflectionException;
use SytxLabs\LaravelWebMcp\Attributes\WebMcp;

/**
 * Reads attributes via reflection without instantiating the class.
 * Walks the parent chain like laravel/mcp does. Caches per class only (no request state, Octane-safe).
 */
final class AttributeResolver
{
    /** @var array<string, object|null> */
    private static array $cache = [];

    /**
     * @param class-string|object $class
     */
    public static function webMcp(object|string $class): ?WebMcp
    {
        return self::find($class, WebMcp::class);
    }

    /**
     * @template T of object
     *
     * @param class-string|object $class
     * @param class-string<T> $attribute
     *
     * @return T|null
     */
    public static function find(object|string $class, string $attribute): ?object
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) { // @phpstan-ignore catch.neverThrown
            return null;
        }
        $key = $reflection->getName().'@'.$attribute;

        if (array_key_exists($key, self::$cache)) {
            $cached = self::$cache[$key];

            return $cached instanceof $attribute ? $cached : null;
        }
        do {
            $attributes = $reflection->getAttributes($attribute);
            if ($attributes !== []) {
                return self::$cache[$key] = $attributes[0]->newInstance();
            }
        } while ($reflection = $reflection->getParentClass());
        self::$cache[$key] = null;

        return null;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
