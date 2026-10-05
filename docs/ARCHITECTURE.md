# Architecture – `sytxlabs/laravel-webmcp`

Namespace `SytxLabs\LaravelWebMcp`. This document describes the implemented design and the decisions behind it.
Companion documents: [WEBMCP-SPEC-NOTES.md](WEBMCP-SPEC-NOTES.md), [SECURITY-REVIEW.md](SECURITY-REVIEW.md).

## 1. Key findings that shaped the design

1. **The spec differs from common assumptions** (annotations, error behavior, `document.modelContext`). See the spec notes.
2. **In-process execution through public API only.** An `InMemoryTransport implements Laravel\Mcp\Server\Contracts\Transport` collects all messages while
   `Server::handle()` runs, so Session mode uses the same code path as `Mcp::web()` (protocol, `shouldRegister` on every call, `ToolInvoker`, error mapping,
   generators) minus HTTP. Requests are "legacy" JSON-RPC (no `_meta`), which laravel/mcp 1.0.1 accepts without headers.
3. **`Mcp::web()` does not reveal its server class**, so servers are registered explicitly (config and fluent API).
4. **One non-public touchpoint:** the protected `Server::$tools`, `$resources`, `$prompts` are read by reflection, because `ServerContext` does not expose
   ToolSearch catalog members and instantiates everything (the opt-in decision must be made without instantiating). Guarded by `tests/Contract`.
5. **`UriTemplate::match()` matches `([^/]+)` and never decodes**, so `..`, `%2e%2e`, `%2F`, NUL, `?`, `#` pass; the package validates them.
6. **Bridge mode cannot enforce the opt-in on the server by itself** (`Mcp::web()` serves all tools), hence `EnforceWebMcpExposure`.
7. **Livewire swallows `ValidationException` in actions** and only persists errors of component properties; a component hook sends them as an effect.
8. **A browser can hold two copies of a module** (`?v=` cache busting vs plain import): the runtime lives in `webmcp-core.js`; `webmcp.js` is a re-exporting entry point.

## 2. Requirements and versions

PHP `^8.3`, `illuminate/*` `^12.41.1|^13.0`, `laravel/mcp ^1.0.1` (first version with `ToolInvoker`, `ToolSearch`, `RendersApp`, `Cacheable`; requires `illuminate/json-schema ^12.41.1|^13`,
so Laravel 11 is excluded). CI covers PHP 8.3/8.4 x Laravel 12/13 x Livewire 3/4 plus `--prefer-lowest`. `tests/Contract` fails when a laravel/mcp update changes a touchpoint.

## 3. Layout

```
src/
  Attributes/    WebMcp, WebMcpAction, WebMcpMode
  Servers/       ServerRegistry (static config), ServerDefinition
  Manifest/      ManifestBuilder (cache + events), ManifestCompiler (the logic), ManifestPayload (browser document),
                 Manifest, ToolDefinition, Exclusion(Reason), NameRegistry (scoped, per request)
  Execution/     ToolExecutor, McpDispatcher, InMemoryTransport, ResourceAccess, ConfirmationGate
  Mapping/       ResultMapper
  Http/          Controllers/{ManifestController,InvokeController}, Middleware/{EnsureWebMcpRequest,EnforceWebMcpExposure}
  Livewire/      ActionManifest, ActionSchema, LivewireActions, ExposesWebMcpActions, ReportsValidationErrors
  Forms/         DeclarativeForms, FormFieldMapper, FormField
  View/          Renderer, NonceResolver, Components/{Tools,Form}
  Cache/         ManifestCache, FlushManifestCache
  Observability/ Recorder, Invocation, AuditLogger, ArgumentMasker      Events/  (4 events)
  Support/       Settings, Localizer, AttributeResolver, OriginAllowlist, ResourceVariables, ToolNameValidator, Json
  Console/       InstallCommand, ListCommand, CheckCommand             Testing/ WebMcpFake
resources/js/    webmcp.js (entry), webmcp-core.js, webmcp-livewire.js, webmcp-alpine.js, webmcp-forms.js, webmcp.d.ts
resources/{views,lang,css}   config/webmcp.php   routes/webmcp.php   workbench/   tests/   js-tests/
```

No request state in singletons (Octane): `ServerRegistry` is static configuration, everything else is bound per resolution or `scoped()`; attribute reflection is cached per class.

## 4. Exposure model

`ManifestCompiler` decides **by reflection, without instantiating**, then instantiates only exposed classes through the container and calls `shouldRegister()` (current request/user).
Order, each rejection recorded as an `Exclusion` (shown by `webmcp:list`):
`toolsearch-meta` -> `prompt` (`#[WebMcp]` on a prompt throws) -> `app-resource` -> `missing-attribute` (default deny; `exposeAll` only on a server) -> `app-only` -> `should-register`
-> resource rules (`confirm` on a resource throws; template without `AuthorizesWebMcpRead` is excluded) -> invalid name / collision (throws) -> `limit`.
Priority: class attribute > server attribute > server registration > config. `exposedTo` only from `webmcp.allowed_origins`, only secure origins.

## 5. Execution

- **Session:** `POST {prefix}/{server}/tools/{tool}` and `/resources/{resource}`, `GET {prefix}/{server}/manifest`. Middleware `web`, `throttle:webmcp`, plus `EnsureWebMcpRequest`
  (JSON only, size limit, `Origin`, `Sec-Fetch-Site`). `ToolExecutor` rebuilds the manifest for the user on every call; unknown, hidden, wrong-mode and wrong-entry-point tools all answer 404.
- **Bridge:** the browser posts JSON-RPC to `Mcp::web()`. `EnforceWebMcpExposure` (optional, recommended) restricts browser-style callers to `tools/call` and `resources/read` on
  Bridge-mode tools/resources the user sees, with the same resource checks.
- **Result convention:** `{content:[{type:"text",text}], isError?}`; failures resolve (spec: a rejection loses the message). `Response::structured` -> JSON text (+ `structuredContent` behind a flag),
  images/audio -> text description, blobs -> configurable fallback, generators collected, notifications dropped.
- **Resources:** variables validated (string/int, length, no `/ \ ? #`, NUL/control chars, `.`/`..`, up to three layers of percent-decoding, optional `variablePattern`),
  composed URI must match the template back to exactly those variables, then `AuthorizesWebMcpRead` on every read. The generic `read-resource` tool only resolves URIs of exposed resources.
- **Confirmation:** client hook; optional server-enforced single-use token bound to user/session, tool and arguments (`confirmation.server_enforced`).

## 6. Frontend

`ManifestPayload` is the single browser document (embedded in the page and returned by the manifest endpoint): version, server, locale, csrf, conventions, endpoints, tools (Bridge tools carry routing).
It never contains class names, paths or secrets. Embedding uses `JSON_HEX_*`; the Blade component returns a View (never a Blade string).

`webmcp-core.js`: registration per tool with an `AbortController`, reconcile (`abort()` removed/changed, register new), refresh coalescing, error mapping, confirm hook, same-origin `fetch` wrapper,
`registerScope()` for non-server tools (Livewire, Alpine) sharing registration, events and error convention, `resync()` for late polyfills, skip of tools declared by `<form toolname>`.
Livewire adapter: one scope per component instance, read from the JSON element on init, re-synced on every commit, aborted on destroy; positional `$wire.$call`; validation effect.
Declarative forms: server renders the attributes from the exposed tool; `webmcp-forms.js` answers agent submits with `preventDefault()` + `respondWith()`.

## 7. Localization, observability, caching

`Localizer` translates strings that exist as keys (descriptions, schema descriptions, `#[WebMcpAction]` texts) and package messages (`webmcp::messages`); names never.
`Recorder` dispatches events and writes the audit log; arguments optional and masked. `ManifestCache` (off by default): key = server, guard, user, locale, epoch, configuration hash;
epochs bumped by `Login`, `Logout`, `PasswordReset`, device logouts and `WebMcp::flush()`; `#[Cacheable]` limits the TTL; `#[WebMcp(cache: false)]` opts a class out.

## 8. Decisions (approved 2026-10-04)

| # | Decision |
|---|---|
| F1 | All package text in English. Namespace `SytxLabs\LaravelWebMcp`. |
| F2 | Errors are resolved results with `isError: true` (`errors.mode` can switch). |
| F3 | `EnforceWebMcpExposure` ships with the package. |
| F4 | Servers registered in config and fluently. |
| F5 | `resources.encode_variables` (off) and `resources.require_authorization` (on) are configurable. |
| F6 | Reflection on the protected `Server` properties accepted, guarded by contract tests. |
| F7 | `navigator.modelContext` used as a fallback (off with `legacyNavigator: false`). |
| F8 | Livewire 3 and 4, plus Alpine. |
| F9 | MIT, holder SytxLabs. |
| F10 | `structuredContent` flag and server-enforced confirmation, both configurable. |

## 9. Test strategy

Pest + Testbench (manifest/mapping, both modes, auth, `shouldRegister`, priority, generators, validation, Livewire, forms, CSP, events, cache, commands, workbench smoke test),
contract tests for laravel/mcp touchpoints, Vitest with a spec-faithful `document.modelContext` mock (promises, abort-unregister, `NotAllowedError`, duplicates, `signal`, refresh, 401/419, real Alpine in jsdom),
PHPStan level max, Pint. An automated end-to-end suite (`npm run e2e`) starts the workbench and drives a real Chrome with `--enable-features=WebMCPTesting`, using the browser's own
`getTools()`/`executeTool()`: registration of server tools, Livewire actions, Alpine tool and the declarative form, execution, validation errors, resource reads in Session and Bridge mode,
login/logout without reload, and `Permissions-Policy: tools=()`. The CI matrix (PHP 8.3/8.4 x Laravel 12/13 x Livewire 3/4, plus `--prefer-lowest`) was reproduced locally except Laravel 12 + Livewire 3 on PHP 8.4.
