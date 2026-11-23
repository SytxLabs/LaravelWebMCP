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

    /** The manifest is inlined so the tools register without a second round trip; the loader shares its cache lifetime. */
    public function script(string $server): Response
    {
        $payload = json_encode($this->payload($server), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $runtime = substr((string) hash_file('sha256', self::RUNTIME), 0, 12);

        return new Response("import { embed } from '../_assets/webmcp-core.js?v={$runtime}';\nembed(new URL('./embed/manifest', import.meta.url).href, {$payload});\n", 200, $this->scriptHeaders($this->manifestCacheControl()));
    }

    public function manifest(string $server): JsonResponse
    {
        return new JsonResponse($this->payload($server), 200, ['Cache-Control' => $this->manifestCacheControl()]);
    }

    /** @return array<string, mixed> */
    private function payload(string $server): array
    {
        return $this->payloads->buildEmbed($this->servers->get($server), $this->manager->manifest($server));
    }

    private function manifestCacheControl(): string
    {
        $maxAge = max(0, $this->settings->int('embed.manifest_max_age', 60));

        return $maxAge > 0 ? 'public, max-age='.$maxAge : 'no-store';
    }

    public function asset(): Response
    {
        return new Response((string) file_get_contents(self::RUNTIME), 200, $this->scriptHeaders('public, max-age=86400'));
    }

    /**
     * @return array<string, string>
     */
    private function scriptHeaders(string $cacheControl): array
    {
        return ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => $cacheControl, 'X-Content-Type-Options' => 'nosniff'];
    }
}
