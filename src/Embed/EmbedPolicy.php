<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Embed;

use Closure;
use Illuminate\Http\Request;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;
use SytxLabs\LaravelWebMcp\Support\OriginAllowlist;
use SytxLabs\LaravelWebMcp\Support\Settings;

/**
 * Embed mode: which servers may be loaded by pages on other origins, and from which origins.
 *
 * Origins come from `webmcp.embed.origins` plus an optional resolver (WebMcp::embedOriginsUsing()), so an app can derive them
 * per request (a tenant's shop host, a feature flag). No origins or no servers means embed mode is off.
 * Only secure origins (https, http on localhost) count, the same rule as `exposedTo`.
 */
final class EmbedPolicy
{
    /** @var (Closure(Request): (iterable<mixed>|null))|null */
    private static ?Closure $resolver = null;

    public function __construct(private readonly Settings $settings, private readonly ServerRegistry $servers)
    {
    }

    /**
     * @param (Closure(Request): (iterable<mixed>|null))|null $resolver
     */
    public static function using(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @return list<string> normalized page origins allowed to embed, for this request
     */
    public function origins(Request $request): array
    {
        $origins = $this->settings->strings('embed.origins');
        if (self::$resolver instanceof Closure) {
            foreach ((self::$resolver)($request) ?? [] as $origin) {
                if (is_string($origin)) {
                    $origins[] = $origin;
                }
            }
        }

        return array_values(array_unique(array_filter(array_map(OriginAllowlist::normalize(...), $origins))));
    }

    /**
     * @return list<string> slugs of registered servers that may be embedded
     */
    public function servers(): array
    {
        return array_values(array_filter($this->settings->strings('embed.servers'), $this->servers->has(...)));
    }

    public function enabled(Request $request): bool
    {
        return $this->servers() !== [] && $this->origins($request) !== [];
    }

    public function embeddable(string $server): bool
    {
        return in_array($server, $this->servers(), true);
    }

    /** The request's Origin, normalized, when it is one of the allowed page origins; null otherwise. */
    public function allowedOrigin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if (!is_string($origin) || $origin === '') {
            return null;
        }
        $normalized = OriginAllowlist::normalize($origin);

        return $normalized !== null && in_array($normalized, $this->origins($request), true) ? $normalized : null;
    }

    /** Whether the request targets an embed route or the Mcp::web() endpoint of an embeddable server (the paths that get CORS headers). */
    public function covers(Request $request): bool
    {
        $path = trim($request->path(), '/');
        $prefix = trim($this->settings->string('routes.prefix', 'webmcp'), '/');
        $servers = $this->servers();
        if ($servers === []) {
            return false;
        }
        if ($path === $prefix.'/_assets/webmcp-core.js') {
            return true;
        }
        foreach ($servers as $slug) {
            if (in_array($path, [$prefix.'/'.$slug.'/embed.js', $prefix.'/'.$slug.'/embed/manifest'], true)) {
                return true;
            }
            $endpoint = $this->servers->get($slug)->endpoint;
            $endpointPath = $endpoint === null ? null : parse_url($endpoint, PHP_URL_PATH);
            if (is_string($endpointPath) && trim($endpointPath, '/') === $path) {
                return true;
            }
        }

        return false;
    }
}
