<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use SytxLabs\LaravelWebMcp\Embed\EmbedPolicy;
use SytxLabs\LaravelWebMcp\Support\OriginAllowlist;

/**
 * Guards the embed routes (always appended): 404 while embed mode is off for this request or the server is not embeddable,
 * 403 for a browser request from an origin that is not allowed. Requests without Origin (same-origin GET, curl) pass: the routes only serve public data (the guest manifest and the runtime).
 */
final readonly class EnsureWebMcpEmbedRequest
{
    public function __construct(private EmbedPolicy $policy)
    {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $server = $request->route('server');

        if (!$this->policy->enabled($request) || (is_string($server) && !$this->policy->embeddable($server))) {
            return $this->reject(404, 'not_found', 'Not found.');
        }

        $origin = $request->headers->get('Origin');
        if (is_string($origin) && $origin !== '' && $this->policy->allowedOrigin($request) === null && OriginAllowlist::normalize($origin) !== strtolower($request->getSchemeAndHttpHost())) {
            return $this->reject(403, 'forbidden_origin', 'This origin may not embed WebMCP tools.');
        }

        return $next($request);
    }

    private function reject(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status, ['Cache-Control' => 'no-store, private']);
    }
}
