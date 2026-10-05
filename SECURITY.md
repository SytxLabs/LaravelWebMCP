# Security Policy

## Supported versions

Security fixes go into the latest minor release of the newest major version. Until 1.0 is tagged, only the latest `0.x` release is supported.

| Version        | Supported |
|----------------|-----------|
| latest release | yes       |
| older releases | no        |

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**
(GitHub private vulnerability reporting). If that is not available to you, contact the maintainers at SytxLabs
through the contact details on the SytxLabs GitHub profile and ask for a private channel before sending details.

Please include:

- the affected version of `sytxlabs/laravel-webmcp`, Laravel, `laravel/mcp`, and Livewire (if used)
- a description of the problem and its impact
- steps or a minimal project to reproduce it (a failing test is ideal)
- whether the problem needs a logged-in user, a specific mode (Session or Bridge) or a specific browser

What to expect:

- acknowledgement within 5 working days
- an assessment and a fix timeline after we reproduced the problem
- a fixed release and a GitHub security advisory; you are credited unless you prefer not to be

Please give us a reasonable time to ship a fix before you disclose the problem publicly.

## Scope

In scope:

- exposing a tool, resource or prompt that has no `#[WebMcp]` attribute (the package is default deny)
- bypassing `exposedTo`, the origin allowlist, CSRF, the `Origin` / `Sec-Fetch-Site` checks, rate limiting or the Bridge guard (`EnforceWebMcpExposure`)
- path traversal or injection through resource URI variables
- script breakout or Blade injection through the embedded manifest
- leaking class names, paths, tokens or other secrets in the manifest, events or audit log
- a bypass of the server-enforced confirmation for consequential tools
- the browser runtime sending requests or credentials to another origin

Out of scope (documented limitations, see `docs/SECURITY-REVIEW.md`):

- A holder of a valid bearer token or session can call the Bridge endpoint directly. The guard protects browser callers, it cannot stop an authenticated API client.
- Livewire requests that an agent started cannot be aborted once sent.
- Vulnerabilities in your own tools, in `laravel/mcp`, Laravel, Livewire or the browser's WebMCP implementation. Report those to the respective project.
- Prompt injection through the content a tool returns. Mark such tools with `untrusted: true` (`untrustedContentHint`) and keep consequential tools behind confirmation.
- Findings that need a malicious dependency or a compromised build machine.

## Hardening checklist for applications

- Expose only what an agent needs: keep `#[WebMcp]` explicit and avoid `exposeAll`.
- Run `php artisan webmcp:check` in CI; it fails on invalid names, collisions, unlisted `exposedTo` origins and warns about risky settings.
- Mark destructive tools with `confirm` and enable `confirmation.server_enforced` when a click in the page is not enough.
- Keep `allowed_origins` as small as possible and send a `Permissions-Policy` header for pages that must not expose tools (`tools=()`).
- Authorize every URI template resource with `AuthorizesWebMcpRead` and a `variablePattern`.
- Enable the audit log (`audit.enabled`) and keep argument masking (`observability.mask`) in place.
- Keep dependencies current (`composer audit`, `npm audit`).
