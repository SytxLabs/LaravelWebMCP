<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use SytxLabs\LaravelWebMcp\Manifest\ManifestPayload;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;
use SytxLabs\LaravelWebMcp\WebMcpManager;

/**
 * GET {prefix}/{server}/manifest: the manifest payload for the CURRENT user, used by the browser runtime to reconcile tools after login/logout, 401 or 419 without a page reload. Carries a fresh CSRF token.
 */
final readonly class ManifestController
{
    public function __construct(private ServerRegistry $servers, private WebMcpManager $manager, private ManifestPayload $payloads)
    {
    }

    public function __invoke(string $server): JsonResponse
    {
        if (!$this->servers->has($server)) {
            return new JsonResponse(['error' => ['code' => 'unknown_server', 'message' => 'Unknown WebMCP server.']], 404, ['Cache-Control' => 'no-store, private']);
        }

        return new JsonResponse($this->payloads->build($this->servers->get($server), $this->manager->manifest($server)), 200, ['Cache-Control' => 'no-store, private']);
    }
}
