<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use SytxLabs\LaravelWebMcp\Support\OriginAllowlist;
use SytxLabs\LaravelWebMcp\Support\Settings;

/**
 * Hardening for the package's own routes (always appended, cannot be removed via config):
 *  - JSON only (no HTML error pages, Accept is forced to JSON)
 *  - body size limit
 *  - Origin and Sec-Fetch-Site checks (same-site browser calls only)
 * CSRF, session and auth come from the `web` group / middleware you configure.
 */
final readonly class EnsureWebMcpRequest
{
    public function __construct(private Settings $settings)
    {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        if ($request->isMethod('POST')) {
            if (!$request->isJson()) {
                return $this->reject(415, 'unsupported_media_type', 'Send application/json.');
            }
            $max = max(1024, $this->settings->int('limits.max_request_bytes', 65536));
            if ($request->header('Content-Length') !== null && (int) $request->header('Content-Length') > $max) {
                return $this->reject(413, 'payload_too_large', 'The request body is too large.');
            }
            if (strlen($request->getContent()) > $max) {
                return $this->reject(413, 'payload_too_large', 'The request body is too large.');
            }
        }
        if (!$this->originAllowed($request)) {
            return $this->reject(403, 'forbidden_origin', 'Cross-origin requests are not allowed.');
        }
        $site = $request->headers->get('Sec-Fetch-Site');
        if (is_string($site) && $site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
            return $this->reject(403, 'forbidden_origin', 'Cross-site requests are not allowed.');
        }
        if ($site === 'none' && $request->isMethod('POST')) {
            return $this->reject(403, 'forbidden_origin', 'Requests must come from the page.');
        }

        return $next($request);
    }

    private function originAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if (!is_string($origin) || $origin === '' || $origin === 'null') {
            return !($request->isMethod('POST') && $origin !== null);
        }
        $normalized = OriginAllowlist::normalize($origin);
        if ($normalized === null) {
            $normalized = strtolower(rtrim($origin, '/'));
        }

        return $normalized === strtolower($request->getSchemeAndHttpHost()) || in_array($normalized, array_values(array_filter(array_map(static fn (string $origin): string => strtolower(rtrim($origin, '/')), $this->settings->strings('request_origins')))), true);
    }

    private function reject(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status, ['Cache-Control' => 'no-store, private']);
    }
}
