# laravel-webmcp

Expose your [`laravel/mcp`](https://laravel.com/docs/mcp) tools and resources to AI agents **in the browser** with
[WebMCP](https://github.com/webmachinelearning/webmcp) (`document.modelContext`). Write a tool once with laravel/mcp,
use it over classic MCP **and** as a WebMCP tool, without duplicating code.

- **Opt-in, default deny.** Nothing is exposed without `#[WebMcp]`.
- **Authorization on every call**, on the server, never only in the manifest.
- **Two modes:** *Session* (package routes, in-process, session + CSRF) and *Bridge* (your `Mcp::web()` endpoint).
- **Integrations:** Blade + vanilla JS, Livewire 3/4 (`#[WebMcpAction]`), Alpine (`x-webmcp`), declarative HTML forms.
- **Spec first.** Follows the W3C WebMCP draft; known gaps are listed in [Spec status](#spec-status).

> WebMCP is an early draft. Browser support is limited (Chrome origin trial / `chrome://flags/#enable-webmcp-testing`).
> Without the API the runtime does nothing, so it is safe to ship today.

## Requirements

PHP 8.3+, Laravel 12 or 13, `laravel/mcp` ^1.0.1. Optional: Livewire ^3.6 or ^4.0, Alpine 3.

## Installation

```bash
composer require sytxlabs/laravel-webmcp
php artisan webmcp:install
```

`webmcp:install` publishes `config/webmcp.php` and the browser assets to `public/vendor/webmcp`
(`php artisan vendor:publish --tag=webmcp-assets` does the assets alone) and prints the remaining steps.

## Quickstart

### 1. Register your MCP server

`Mcp::web()` does not reveal its server class, so register it for WebMCP explicitly, in `config/webmcp.php`:

```php
'servers' => [
    'shop' => ['class' => App\Mcp\Servers\ShopServer::class, 'endpoint' => '/mcp/shop'],
],
```

or fluently in a service provider:

```php
use SytxLabs\LaravelWebMcp\Facades\WebMcp;

WebMcp::server('shop', ShopServer::class, endpoint: '/mcp/shop');
```

### 2. Opt in per tool and resource

```php
use SytxLabs\LaravelWebMcp\Attributes\WebMcp;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;

#[WebMcp(mode: WebMcpMode::Session)]          // Bridge | Session | Inherit
#[IsReadOnly]
class SearchProductsTool extends Tool { /* ... */ }
```

Attribute options: `mode`, `name`, `exposedTo`, `confirm`, `allowAppOnly`, `untrusted`, `debugging`,
`variablePattern` (resources), `cache`, `sharedCache`; server-only: `exposeAll`, `prefix`.
Priority: class attribute > server attribute > server registration > `config/webmcp.php`.
A `#[WebMcp]` on the **server** only sets defaults; classes without their own attribute stay hidden
(unless you set `exposeAll: true`, which is risky: every tool and resource of that server becomes visible).

### 3. Put it in your layout

```blade
<meta name="csrf-token" content="{{ csrf_token() }}">
@webmcp(App\Mcp\Servers\ShopServer::class)
```

`@webmcp` (or `<x-webmcp::tools server="shop" />`) renders the manifest of the **current user** as
`<script type="application/json">` and loads the runtime once. Several servers: `@webmcp(['shop', 'support'])`.
Skip the script tag when you bundle the runtime yourself: `@webmcp(ShopServer::class, false)`.

With Vite: `import '../../vendor/sytxlabs/laravel-webmcp/resources/js/webmcp.js'`.

Check what is exposed, and why something is not:

```bash
php artisan webmcp:list --user=1        # tools, modes, sources, exclusion reasons
php artisan webmcp:check                # for CI: fails on configuration errors
```

## What is exposed

| laravel/mcp                             | WebMCP                                                                                        |
|-----------------------------------------|-----------------------------------------------------------------------------------------------|
| Tool                                    | tool with `name`, `title`, `description`, full `inputSchema` (`{type, properties, required}`) |
| `#[IsReadOnly]`                         | `readOnlyHint`                                                                                |
| `#[IsDestructive]`                      | `consequentialHint` + confirmation                                                            |
| `#[IsIdempotent]`, `#[IsOpenWorld]`     | dropped (no WebMCP counterpart)                                                               |
| Static resource                         | read-only tool `read-<resource-name>`                                                         |
| Resource template (`HasUriTemplate`)    | read-only tool with one required string argument per URI variable                             |
| ToolSearch catalog members              | registered individually; `search_tools`/`execute_tools` are never exposed                     |
| `#[RendersApp(visibility: [App])]` tool | excluded unless `#[WebMcp(allowAppOnly: true)]`                                               |
| `AppResource`, Prompts                  | never exposed. `#[WebMcp]` on a prompt is an error                                            |
| `shouldRegister()`                      | evaluated for the current request/user, on the manifest **and on every call**                 |

Resource tools are always `readOnlyHint` and `untrustedContentHint`. Blobs become a text fallback with MIME type and
size (`resources.blobs`: `fallback` | `mcp-image` | `base64-text`), because the spec does not define multimodal results yet.
Template resources are only exposed when the resource class implements `AuthorizesWebMcpRead` (IDOR protection),
unless you set `resources.require_authorization=false`:

```php
class InvoiceResource extends Resource implements HasUriTemplate, AuthorizesWebMcpRead
{
    public function authorizeWebMcpRead(Request $request, array $variables): bool
    {
        return $request->user()?->can('view', Invoice::findOrFail($variables['id'])) ?? false;
    }
}
```

## Execution modes

|                               | Session (default)                                             | Bridge                                                                               |
|-------------------------------|---------------------------------------------------------------|--------------------------------------------------------------------------------------|
| Browser calls                 | `POST /webmcp/{server}/tools/{tool}`, `/resources/{resource}` | JSON-RPC `tools/call` / `resources/read` on your `Mcp::web()` URL                    |
| Auth                          | `web` group: session, CSRF, your `auth` middleware            | Sanctum SPA cookie (`X-XSRF-TOKEN`) or bearer token (`WebMcp.configure({ bearer })`) |
| Execution                     | in-process through laravel/mcp's public API                   | your existing MCP endpoint                                                           |
| Opt-in enforced on the server | always                                                        | with `EnforceWebMcpExposure` (see below)                                             |

Both return the same result to the browser: `{ content: [{ type: "text", text }], isError?: true }`.
Failures are **resolved results with `isError: true`**, not rejections, because a rejected `execute()` reaches the agent
as a bare `UnknownError` without the message (`errors.mode = reject` switches this). Validation errors become a short
text ("The city field is required."), generator/SSE responses are collected into one result, notifications are dropped.

### Bridge guard

`Mcp::web()` serves **every** tool of a server to anyone who may reach it, regardless of `#[WebMcp]`. Attach the guard so
browser callers (marker header or session cookie) only get what you opted in:

```php
Mcp::web('/mcp/shop', ShopServer::class)
    ->middleware(['web', EnforceWebMcpExposure::class.':shop']);   // ':shop' = WebMCP server slug
```

It allows only `tools/call` and `resources/read` for Bridge-mode tools/resources the user sees, with the same resource checks
as Session mode. Bearer-token MCP clients without cookie or marker are untouched (`bridge.enforce`: `browser` | `always` | `never`).
A script that holds a bearer token has the token's full power; the guard cannot change that.

## Frontend

### Runtime (`webmcp.js`)

- Feature detection `"modelContext" in document`; otherwise a no-op. `navigator.modelContext` (pre-spec) is used as a
  fallback when `document.modelContext` is missing (`WebMcp.configure({ legacyNavigator: false })` turns it off).
  `resolveModelContext` lets a polyfill provide the API; `WebMcp.resync()` registers everything once an API appears late.
- `await document.modelContext.registerTool(tool, { signal, exposedTo })`, one `AbortController` per tool, unregistration only through `abort()`.
- `NotAllowedError` (Permissions-Policy), duplicate names (`InvalidStateError`) and `SecurityError` are reported as `webmcp:error` events, the page keeps working.
- `options.signal` of `execute` is passed to `fetch`.
- **Auth at runtime:** `GET /webmcp/{server}/manifest` is called on `webmcp:auth-changed` (dispatch it after login/logout),
  on 401/404, when the tab regains focus, and after 419 (fresh CSRF token, the call is retried once). Removed tools are
  aborted, new ones registered, changed ones replaced.
- Only talks to its own origin: endpoint URLs come from the DOM, so a foreign URL is refused.
- Confirmation: `WebMcp.configure({ confirm: async ({ tool, title, description, arguments }) => boolean })`; default `window.confirm`,
  or `WebMcp.createDialogConfirm()` for a native `<dialog>`. Runs for `consequentialHint` tools. It is client-side and no security boundary;
  `confirmation.server_enforced` adds a single-use token step (a speed bump against blind calls, not proof of a user gesture).
- API: `WebMcp.register(server)`, `unregister`, `refresh`, `configure`, `on`/`off`, events `webmcp:invoked|succeeded|failed|registered|unregistered|refreshed|error|skipped|unsupported`.
- Typed with [`webmcp-types`](https://www.npmjs.com/package/webmcp-types) (`resources/js/webmcp.d.ts`).

### SPA navigation (`wire:navigate`, Turbo)

The runtime re-reads the embedded manifests after `livewire:navigated` and `turbo:load`: servers whose manifest left the page are
unregistered, new or changed ones are registered, identical ones are left alone. Livewire components and Alpine elements clean up
with their component/element. **Render `@webmcp` inside `<body>`, not `<head>`:** Livewire keeps old `<head>` elements across
navigations, so a manifest in the head would outlive its page. Module scripts also keep running after a navigation: guard
page-specific code in your own event listeners.

### CSP

The manifest and runtime tags carry a nonce from `Vite::cspNonce()` (`Vite::useCspNonce()`), or set your own:
`WebMcp::nonceUsing(fn () => $nonce)`. The manifest is embedded as inert JSON with `<`, `>`, `&`, `'`, `"` escaped.

### Livewire

```php
class Cart extends Component
{
    use ExposesWebMcpActions;     // optional: webMcpInstanceKey(), webMcpAvailable()

    #[WebMcpAction(description: 'Adds a product to the cart', parameters: ['qty' => 'How many'])]
    public function addToCart(int $productId, int $qty = 1): string { /* ... */ }
}
```

```blade
<div>
    @webmcpActions
    ...
</div>
```

```html
<script type="module" src="/vendor/webmcp/webmcp-livewire.js"></script>
```

The schema comes from the method signature (`int`, `float`, `string`, `bool`, `array`, `BackedEnum`, Eloquent model as its key,
nullable, defaults, variadics); override with `schema: [...]`. The browser runs the action with `$wire.$call`, so Livewire's
checksum, authorization attributes, validation and your policies stay in charge. **The attribute adds the agent as a caller; it
does not add server-side power a user did not already have** and `webMcpAvailable()` is not an authorization check.
Components that appear several times need `webMcpInstanceKey()`; names must be unique. Validation errors thrown inside an
action are reported to the agent (Livewire would otherwise swallow them). An agent abort stops waiting; the request itself
cannot be cancelled in Livewire.

### Alpine

```html
<script type="module" src="/vendor/webmcp/webmcp-alpine.js"></script>
<div x-data="{ count: 0 }">
    <span x-webmcp="{ name: 'counter.increment', description: 'Increments the counter', execute: ({ by = 1 }) => { count += by; return 'count is ' + count } }"></span>
</div>
```

### Declarative forms

```blade
<x-webmcp::form :tool="App\Mcp\Tools\SearchProductsTool::class" action="/search" method="get" submit="Search" />
```

renders `<form toolname tooldescription [toolautosubmit]>` with fields carrying `name` and `toolparamdescription`, following the
[declarative API explainer](https://github.com/webmachinelearning/webmcp/blob/main/declarative-api-explainer.md).
To annotate an existing form: `<form @webmcpForm(SearchProductsTool::class)>` and `<input name="q" @webmcpParam(SearchProductsTool::class, 'q')>`.

- Auto-submit only for `#[IsReadOnly]` tools, or explicitly (`:autosubmit="true"`), never for consequential tools unless `forms.allow_autosubmit_consequential`.
- `webmcp-forms.js` answers agent-invoked submits with `event.preventDefault()` + `event.respondWith(result)`; user submits go to `action`.
- `:omit="['field']"` skips properties you render yourself, `:controls="false"` renders only the annotated `<form>`.
- Mapped: `string` (formats email/uri/date/date-time/time, length, pattern), `integer`/`number` (min, max, step), `boolean` (checkbox), enums (select), nullable.
  **Not mappable:** nested objects, arrays (even of enums), `oneOf`/`anyOf`/`allOf`/`not`/`$ref`/`if`, `patternProperties`, `prefixItems`, binary/file strings, multi-type unions,
  properties without a type (a clear exception names the property); exclusive bounds are ignored.
- The spec does not define the form-to-schema synthesis yet and leaves the response after a navigation open (#135). A declarative form wins over an imperative tool of the same name.
- Optional highlight for agent-filled forms: `public/vendor/webmcp/webmcp-forms.css` (`:tool-form-active`, `:tool-submit-active`).

## Security

**Permissions-Policy and iframes.** WebMCP is gated by the `tools` policy-controlled feature, default `'self'`:

```
Permissions-Policy: tools=()                        # disable WebMCP for the page and all frames
<iframe src="https://agent.example" allow="tools">  # allow it in a cross-origin frame
```

`#[WebMcp(exposedTo: ['https://agent.example'])]` additionally exposes a tool to a cross-origin frame; only secure origins
(`https://`, `http://localhost`) that are listed in `webmcp.allowed_origins` are accepted.

Built in: default deny, authorization on every call, CSRF + `Origin`/`Sec-Fetch-Site` checks, JSON-only and a body limit,
rate limiting (`throttle:webmcp`), argument validation (always server-side, even if the browser validates later), path
traversal/IDOR protection for resource variables, no class names or paths in the manifest, escaped embedding, same-origin-only runtime.

Treat tool arguments **and tool/resource results as untrusted** (prompt injection): resource tools carry `untrustedContentHint`; set `#[WebMcp(untrusted: true)]` on tools that return user-generated content. Declarative forms are declared by HTML: do not let
untrusted HTML contain `toolname` attributes. To report a vulnerability, see [SECURITY.md](SECURITY.md).

## Localization

Tool titles, descriptions, parameter descriptions (also `parameters:` of `#[WebMcpAction]`) can be plain text or translation keys; the
manifest is rendered in the current locale (`Description('shop.search.description')`). Tool names are never translated. The sentences the package
generates come from `webmcp::messages` (en, de; override with `php artisan vendor:publish --tag=webmcp-lang`). Runtime error texts for the agent are English.

## Observability

Events: `WebMcpToolInvoked`, `WebMcpToolSucceeded`, `WebMcpToolFailed` (with a `reason`), `WebMcpManifestBuilt` (user, server, tool, mode, duration;
arguments only with `events.include_arguments`, masked by key). Optional audit log (`audit.*`: channel, only consequential tools by default, masked arguments).
The runtime sends the header `X-WebMCP: 1` (`header`) so rate limits and logs can tell agent calls apart.

## Limits

`limits.max_tools` (default 64) caps the tools of one server, `limits.max_tools_per_page` (default 128, `0` = unlimited) caps all servers
rendered on one page: an agent should not get hundreds of tools. What is dropped is logged as a warning and listed by `webmcp:list`
(`limit`). Title and description lengths are capped too (`limits.max_title`, `limits.max_description`); `limits.max_request_bytes` bounds request bodies.

## Performance and Octane

`cache.enabled` caches the manifest per server, guard, user, locale and configuration (login/logout flush the user, `WebMcp::flush($user)` on demand,
`#[Cacheable]` ttl as upper bound). It is off by default: `shouldRegister()` that depends on more than the user (IP, flags, time) needs `#[WebMcp(cache: false)]`.
Only classes that pass the opt-in check are instantiated. No request state lives in singletons, so the package is Octane-safe.

## Testing your app

```php
$fake = WebMcp::fake();                                   // records calls; stub with ->respondWith('tool', 'text')
$this->actingAs($user)->postJson('/webmcp/shop/tools/search', ['arguments' => ['query' => 'hat']]);

$fake->assertToolExposed('search')->assertToolNotExposed('place-order')->assertToolInvoked('search');
```

## Spec status

Spec: <https://github.com/webmachinelearning/webmcp> (draft).

| Spec feature                                                                                 | Status                                                                                                           |
|----------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------|
| `registerTool(tool, { signal, exposedTo })`, abort to unregister                             | supported                                                                                                        |
| `ToolAnnotations` (`readOnlyHint`, `consequentialHint`, `untrustedContentHint`, `debugging`) | supported                                                                                                        |
| `title`, `inputSchema`                                                                       | supported                                                                                                        |
| Result format                                                                                | spec says `any`; package uses `{content, isError?}`                                                              |
| Errors                                                                                       | resolved `isError` result (a rejection reaches the agent as `UnknownError` only); `errors.mode=reject` available |
| Permissions policy `tools`, `allow="tools"`                                                  | documented, `NotAllowedError` handled                                                                            |
| Declarative attributes, `SubmitEvent.agentInvoked/respondWith`                               | supported; schema synthesis is not specified yet                                                                 |
| `outputSchema` (#9)                                                                          | not in the spec; flag `features.output_schema` adds it as an extra member (off)                                  |
| User confirmation (#165, #50)                                                                | open; client confirm hook + optional server token                                                                |
| Streaming (#82), progress                                                                    | open; generator output is aggregated, notifications dropped                                                      |
| Multimodal (#41, #86)                                                                        | open; text fallback with MIME type                                                                               |
| Cross-document response (#135)                                                               | open; forms answer through `respondWith`                                                                         |
| Input/output validation by the browser (#92)                                                 | open; always validated server-side                                                                               |
| `getTools()` / `executeTool()` (in-page agents)                                              | not used by this package                                                                                         |
| `toolchange` / `toolactivated` / `toolcancel`                                                | not used; runtime events are `webmcp:*`                                                                          |
| `navigator.modelContext`                                                                     | not in the spec; used only as a fallback                                                                         |

## Example app

`workbench/` is a small shop that shows every integration on one page. Start it and open <http://127.0.0.1:8000>
(in Chrome with `chrome://flags/#enable-webmcp-testing`, otherwise the page says "not available (no-op)"):

```bash
composer serve                  # builds the workbench and serves it
```

`composer build` only publishes the browser assets. If `php` on your PATH is not the version you want (for example on
Windows with several PHP installations), call Composer through the right binary, e.g.
`C:\Users\<you>\.config\herd\bin\php83.bat C:\Users\<you>\.config\herd\bin\composer.phar serve`.

| Section on the page       | Shows                                                                                                                                                                                                                      |
|---------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Header, **Log in** button | the manifest follows the user: `place-order-tool` (consequential, confirmation) appears on login and disappears on logout, without a reload (`webmcp:auth-changed`)                                                        |
| 1. Imperative tools       | `@webmcp(ShopServer::class)`: Session mode (`read-product-resource`, a URI template with `AuthorizesWebMcpRead` and `variablePattern`) and Bridge mode (`read-catalog-resource` through the guarded `Mcp::web()` endpoint) |
| 2. Declarative form       | `<x-webmcp::form :tool="SearchProductsTool::class">`: read-only tool, `toolautosubmit`, answered with `respondWith`                                                                                                        |
| 3. Livewire actions       | `Cart` with `#[WebMcpAction]` (`cart.add-to-cart`, `cart.clear`, `cart.list`), validation errors reach the agent                                                                                                           |
| 4. Alpine                 | `x-webmcp` counter tool                                                                                                                                                                                                    |
| 5. SPA navigation         | `wire:navigate` to a second page: tools of the old page go away, those of the new page appear                                                                                                                              |
| Event log                 | every `webmcp:*` runtime event                                                                                                                                                                                             |

The code is in `workbench/app` (server, tools, resources, Livewire component, provider) and `workbench/resources/views`.
The same app is the target of the end-to-end suite and of `tests/Feature/WorkbenchTest.php`.

## Development

```bash
composer install && npm install
composer test            # PHP tests (Pest, Testbench)
composer analyse         # PHPStan (level max)
composer cs              # code style check (Pint); composer csfix fixes it
composer serve           # builds the workbench example app and serves it on http://127.0.0.1:8000
npx vitest run           # browser runtime tests with a spec-faithful document.modelContext mock
npx tsc --noEmit -p jsconfig.json
npm run e2e              # real Chrome with the WebMCP flag against the workbench (E2E_PHP, CHROME_PATH)
```

The E2E suite starts the workbench and drives Chrome with `--enable-features=WebMCPTesting`. It uses the browser's own
`getTools()`/`executeTool()`. Chrome 154 expects the `executeTool` arguments as a JSON **string** (the spec IDL says object);
the suite tries both.

## License

MIT. See [LICENSE](LICENSE).
