<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use SytxLabs\LaravelWebMcp\Embed\EmbedPolicy;
use SytxLabs\LaravelWebMcp\Support\Settings;

/**
 * CORS for embed mode (global middleware, only when `webmcp.embed.servers` is set). Covers the embed routes and the Mcp::web() endpoints of embeddable servers, nothing else.
 *
 *  - Preflight (OPTIONS with Access-Control-Request-Method) from an allowed page origin is answered here with 204, before routing:
 *    the Mcp::web() route has no OPTIONS route and its route middleware would not run for one.
 *  - Other responses get `Access-Control-Allow-Origin: <origin>` for an allowed origin and always `Vary: Origin`.
 *  - Never `Access-Control-Allow-Credentials`: embedded calls carry no cookies, so a page cannot borrow a session of this app.
 */
final readonly class HandleWebMcpEmbedCors
{
    public function __construct(private EmbedPolicy $policy, private Settings $settings)
    {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->policy->covers($request)) {
            return $next($request);
        }
        $origin = $this->policy->allowedOrigin($request);

        if ($request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method') && $origin !== null) {
            return new Response('', 204, [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'GET, POST',
                'Access-Control-Allow-Headers' => implode(', ', array_values(array_unique([
                    'Accept',
                    'Content-Type',
                    'X-Requested-With',
                    'MCP-Protocol-Version',
                    $this->settings->string('header', 'X-WebMCP'),
                    $this->settings->string('confirmation.header', 'X-WebMCP-Confirmation'),
                ]))),
                'Access-Control-Max-Age' => (string) max(0, $this->settings->int('embed.max_age', 600)),
                'Vary' => 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
            ]);
        }

        $response = $next($request);
        $response->setVary('Origin', false);
        if ($origin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
        return $response;
    }
}
