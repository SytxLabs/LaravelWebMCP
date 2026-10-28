<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use SytxLabs\LaravelWebMcp\Manifest\ManifestPayload;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;
use SytxLabs\LaravelWebMcp\Support\Settings;
use SytxLabs\LaravelWebMcp\WebMcpManager;

/**
 * Embed mode routes (stateless, no session or CSRF):
 *
 *  - GET {prefix}/{server}/embed.js the one tag a foreign page needs: `<script type="module" src="…/embed.js">`
 *  - GET {prefix}/{server}/embed/manifest guest manifest of the server's Bridge-mode tools, absolute endpoints
 *  - GET {prefix}/_assets/webmcp-core.js the runtime, served here because module scripts need CORS
 */
final readonly class EmbedController
{
    private const string RUNTIME = __DIR__.'/../../../resources/js/webmcp-core.js';

    public function __construct(private ServerRegistry $servers, private WebMcpManager $manager, private ManifestPayload $payloads, private Settings $settings)
    {
    }

    public function script(string $server): Response
    {
        return new Response("import { embed } from '../_assets/webmcp-core.js?v=". substr((string) hash_file('sha256', self::RUNTIME), 0, 12) ."';\nembed(new URL('./embed/manifest', import.meta.url).href);\n", 200, $this->scriptHeaders(300));
    }

    public function manifest(string $server): JsonResponse
    {
        $maxAge = max(0, $this->settings->int('embed.manifest_max_age', 60));
        return new JsonResponse($this->payloads->buildEmbed($this->servers->get($server), $this->manager->manifest($server)), 200, ['Cache-Control' => $maxAge > 0 ? 'public, max-age='.$maxAge : 'no-store']);
    }

    public function asset(): Response
    {
        return new Response((string) file_get_contents(self::RUNTIME), 200, $this->scriptHeaders(86400));
    }

    /**
     * @return array<string, string>
     */
    private function scriptHeaders(int $maxAge): array
    {
        return ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age='.$maxAge, 'X-Content-Type-Options' => 'nosniff'];
    }
}
