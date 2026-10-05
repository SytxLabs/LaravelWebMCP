<?php

use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | When false no manifest contains any tool. Default deny stays in force
    | either way: nothing is exposed without a #[WebMcp] attribute.
    */
    'enabled' => env('WEBMCP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    | slug => ServerClass::class
    | slug => ['class' => ..., 'endpoint' => '/mcp/weather', 'mode' => 'session|bridge', 'prefix' => 'weather_']
    |
    | Mcp::web() does not reveal its server class, so servers are registered
    | explicitly. 'endpoint' is the Mcp::web() URI (needed for Bridge mode).
    | You can also register in a service provider: WebMcp::server('weather', WeatherServer::class).
    */
    'servers' => [
        // 'weather' => ['class' => App\Mcp\Servers\WeatherServer::class, 'endpoint' => '/mcp/weather'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults (lowest priority: class attribute > server attribute > config)
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'mode' => WebMcpMode::Session->value,
        'confirm' => null, // null = derived from #[IsDestructive]
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed origins for #[WebMcp(exposedTo: [...])]
    |--------------------------------------------------------------------------
    | Only secure origins (https, or http on localhost) listed here can be used.
    | Anything else makes the manifest build fail loudly.
    */
    'allowed_origins' => [],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'max_tools' => 64,          // per server; the rest is dropped with a warning
        'max_tools_per_page' => 128, // across all servers rendered on one page (0 = unlimited)
        'max_title' => 256,
        'max_description' => 4096,
        'max_request_bytes' => 65536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Session mode routes
    |--------------------------------------------------------------------------
    | GET  {prefix}/{server}/manifest
    | POST {prefix}/{server}/tools/{tool}
    | POST {prefix}/{server}/resources/{resource}
    |
    | Keep 'web' (session + CSRF). Add 'auth' or 'auth:sanctum' if every tool needs a login.
    | EnsureWebMcpRequest (JSON only, size limit, Origin / Sec-Fetch-Site checks) is always appended.
    */
    'routes' => [
        'enabled' => true,
        'prefix' => 'webmcp',
        'middleware' => ['web', 'throttle:webmcp'],
    ],

    'rate_limit' => [
        'per_minute' => 60, // per user, or per IP for guests (named limiter "webmcp")
    ],

    /*
    | Extra origins accepted on Session mode requests besides the app's own origin (e.g. a separate SPA host).
    */
    'request_origins' => [],

    /*
    | Header the browser runtime adds to every WebMCP call so rate limits and logs can tell agent
    | calls from normal requests. It is a label, not a security boundary.
    */
    'header' => 'X-WebMCP',

    /*
    | Server-enforced confirmation for consequential tools (#[IsDestructive] / confirm: true).
    | Two-step single-use token. A speed bump against blind calls, not proof of a user gesture.
    */
    'confirmation' => [
        'server_enforced' => false,
        'ttl' => 120,
        'header' => 'X-WebMCP-Confirmation', // Bridge mode: confirmation token travels in this header
    ],

    /*
    | EnforceWebMcpExposure (middleware for Mcp::web() routes, Bridge mode):
    |   browser = guard requests that carry the marker header or the session cookie (default)
    |   always  = guard every caller of that route
    |   never   = disable the guard
    */
    'bridge' => [
        'enforce' => 'browser',
    ],

    /*
    | Declarative forms (<x-webmcp::form>, @webmcpForm). Auto-submit is only on for read-only tools unless you
    | set it explicitly, and never for consequential tools unless you allow it here.
    */
    'forms' => [
        'allow_autosubmit_consequential' => false,
    ],

    /*
    | Livewire: #[WebMcpAction] methods are published by @webmcpActions inside the component view.
    */
    'livewire' => [
        'enabled' => true,
    ],

    /*
    | Browser runtime. `php artisan vendor:publish --tag=webmcp-assets` copies it to public/vendor/webmcp.
    | Set script=false (or @webmcp(Server::class, false)) when you bundle resources/js/webmcp.js yourself (Vite).
    | The CSP nonce comes from Vite::cspNonce() unless you call WebMcp::nonceUsing(fn () => ...).
    */
    'assets' => [
        'script' => true,
        'url' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Events and audit log
    |--------------------------------------------------------------------------
    | WebMcpToolInvoked / Succeeded / Failed and WebMcpManifestBuilt are always dispatched. Arguments are only
    | attached when include_arguments is on, and sensitive keys are masked (keys containing a fragment below).
    | The audit log writes one line per call to a log channel; by default only for consequential tools.
    */
    'events' => [
        'include_arguments' => false,
    ],

    'observability' => [
        'mask' => ['password', 'token', 'secret', 'authorization', 'card', 'iban', 'ssn', 'api_key', 'apikey', 'cvv'],
    ],

    'audit' => [
        'enabled' => false,
        'channel' => null,              // null = default log channel
        'only_consequential' => true,
        'include_arguments' => true,    // masked
    ],

    /*
    |--------------------------------------------------------------------------
    | Manifest cache (off by default)
    |--------------------------------------------------------------------------
    | Per server, guard, user, locale and configuration; login/logout flush the user. shouldRegister() that
    | depends on more than the user (IP, flags, time) needs #[WebMcp(cache: false)] on that class.
    */
    'cache' => [
        'enabled' => false,
        'ttl' => 300,       // seconds; laravel/mcp #[Cacheable] ttlMs is honored as an upper bound
        'store' => null,    // null = default cache store
    ],

    'errors' => [
        'mode' => 'result', // result: resolve with isError (agent-readable) | reject: reject execute()
    ],

    /*
    |--------------------------------------------------------------------------
    | Instantiation
    |--------------------------------------------------------------------------
    | Only classes that pass the opt-in check are ever instantiated, always via the container.
    */

    /*
    |--------------------------------------------------------------------------
    | Resources exposed as read-only tools
    |--------------------------------------------------------------------------
    */
    'resources' => [
        'untrusted' => true,                // untrustedContentHint on resource tools
        'append_annotations' => true,       // Audience / Priority / LastModified into the description
        'require_authorization' => true,    // template resources need AuthorizesWebMcpRead (IDOR protection)
        'generic_reader' => false,          // adds a "read-resource" tool (allowlist = exposed resources)
        'variable_max_length' => 255,
        'encode_variables' => false,        // rawurlencode URI template variables before composing the URI
        'blobs' => 'fallback',              // fallback | mcp-image | base64-text
        'blob_max_bytes' => 262144,
    ],

    /*
    |--------------------------------------------------------------------------
    | Spec feature flags (see README "Spec feature -> support" table)
    |--------------------------------------------------------------------------
    */
    'features' => [
        'output_schema' => false,       // WebMCP defines no outputSchema (#9); adds the key as extra dictionary member
        'structured_content' => false,  // add `structuredContent` next to the JSON text of Response::structured()
    ],
];
