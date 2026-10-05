<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\View;

use Closure;
use Illuminate\Support\Facades\Vite;

/**
 * CSP nonce for the manifest and runtime tags. Uses Vite::cspNonce() (Vite::useCspNonce()) unless a resolver was set with WebMcp::nonceUsing(). The resolver is application wiring set at boot, not request state.
 */
final class NonceResolver
{
    private static ?Closure $resolver = null;

    public static function using(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public function resolve(): ?string
    {
        $nonce = self::$resolver instanceof Closure ? (self::$resolver)() : Vite::cspNonce();

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }
}
