<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Support;

use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;

/**
 * `exposedTo` handling: secure origins only (https, or http on localhost) and only from the config allowlist.
 * Mirrors the spec's "potentially trustworthy origin" requirement, which makes registerTool throw SecurityError.
 */
final class OriginAllowlist
{
    public static function normalize(string $origin): ?string
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $path = $parts['path'] ?? '';
        if (($path !== '' && $path !== '/') || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme !== 'https' && !($scheme === 'http' && (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.localhost')))) {
            return null;
        }

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * @param list<string> $requested
     * @param array<int, string> $allowed
     *
     * @return list<string> normalized origins
     */
    public static function assertAllowed(array $requested, array $allowed, string $source): array
    {
        $allowedNormalized = array_filter(array_map(self::normalize(...), $allowed));
        $result = [];

        foreach ($requested as $origin) {
            $normalized = self::normalize($origin);
            if ($normalized === null) {
                throw new InvalidWebMcpConfigurationException("exposedTo origin [{$origin}] on [{$source}] is not a secure origin (use https://host or http://localhost).");
            }
            if (!in_array($normalized, $allowedNormalized, true)) {
                throw new InvalidWebMcpConfigurationException("exposedTo origin [{$normalized}] on [{$source}] is not listed in config webmcp.allowed_origins.");
            }
            $result[] = $normalized;
        }

        return array_values(array_unique($result));
    }
}
