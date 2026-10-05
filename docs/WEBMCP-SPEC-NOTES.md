# WebMCP spec notes

Source: <https://github.com/webmachinelearning/webmcp>, `main` @ `6891d0e857a0b35d8478aa8a01958565fb5466cd`
(read 2026-10-04: `README.md`, `index.bs`, `declarative-api-explainer.md`, `implementation-status.md`).
The spec is a **draft** (Community Group). Everything below is the state of that commit, not stable.

## 0. Corrections to common assumptions

| Assumption                                    | Actual spec state                                                                                                                                  |
|-----------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------|
| MCP-like annotations (`destructiveHint`, ...) | `ToolAnnotations` has only `readOnlyHint`, `untrustedContentHint`, `consequentialHint`, `debugging` (booleans, default `false`).                   |
| `#[IsDestructive]` means "confirm"            | Mapped to `consequentialHint: true` ("significant, real-world, or non-reversible"). Confirming is up to client/agent; no mechanism yet (#165/#50). |
| `navigator.modelContext`                      | Spec and `implementation-status.md` name only `document.modelContext`. The package uses `navigator.modelContext` only as a documented fallback.    |
| `outputSchema`                                | Not in `ModelContextTool` (open question #9).                                                                                                      |
| Errors as rejections                          | A rejected `execute` reaches the agent as a generic `UnknownError`; the message is lost.                                                           |
| `title`                                       | Exists (`USVString title`), optional. Mapped.                                                                                                      |

## 1. IDL (normative, `index.bs`)

```webidl
partial interface Document {
  [SecureContext, SameObject] readonly attribute ModelContext modelContext;
};

[Exposed=Window, SecureContext]
interface ModelContext : EventTarget {
  Promise<undefined> registerTool(ModelContextTool tool, optional ModelContextRegisterToolOptions options = {});
  Promise<sequence<RegisteredTool>> getTools(optional ModelContextGetToolOptions options = {});
  Promise<DOMString> executeTool(RegisteredTool tool, optional object inputObject, optional ModelContextExecuteToolOptions options = {});
  attribute EventHandler ontoolchange;  attribute EventHandler ontoolactivated;  attribute EventHandler ontoolcancel;
};

dictionary ModelContextTool {
  required DOMString name;  USVString title;  required DOMString description;
  object inputSchema;  required ToolExecuteCallback execute;  ToolAnnotations annotations;
};
dictionary ToolAnnotations { boolean readOnlyHint = false; boolean untrustedContentHint = false;
                             boolean consequentialHint = false; boolean debugging = false; };
dictionary ToolExecuteCallbackOptions { required AbortSignal signal; };
callback ToolExecuteCallback = Promise<any> (object inputObject, ToolExecuteCallbackOptions options);
dictionary ModelContextRegisterToolOptions { sequence<USVString> exposedTo; AbortSignal signal; };
dictionary ModelContextGetToolOptions     { sequence<USVString> fromOrigins; };
dictionary ModelContextExecuteToolOptions { AbortSignal signal; };
dictionary RegisteredTool { required DOMString name; DOMString title; required DOMString description;
  object inputSchema; required Window window; required USVString origin; ToolAnnotations annotations; };
```

Events: `toolchange`, `toolactivated` (`toolName`), `toolcancel` (`toolName`).
There is **no** `unregisterTool`, `provideContext`, `clearContext` or `updateTool` (#167/#255 open). Unregister = `AbortController.abort()`.

## 2. `registerTool(tool, options)`

Checks, in spec order, all as **rejected promises**:
1. document not fully active -> `InvalidStateError`
2. permissions policy feature `tools` not allowed -> `NotAllowedError`
3. name already registered -> `InvalidStateError` (**duplicate**)
4. name empty, longer than 128, or a character outside `[A-Za-z0-9_.-]` -> `InvalidStateError`
5. empty `description` -> `InvalidStateError`
6. `inputSchema` not JSON-serializable -> the `JSON.stringify` exception
7. `options.signal` already aborted -> rejected with `signal.reason`
8. `exposedTo`: each entry must parse as a URL **and** be a potentially trustworthy origin, else `SecurityError`

Afterwards the tool is added, `toolchange` fires, the promise resolves with `undefined`.
**Aborting later removes the tool and rejects the registration promise with `signal.reason`**: always attach `.catch()` to the
`registerTool` promise, otherwise every unregister is an unhandled rejection. Unregistering does not cancel running executions.

## 3. Execution and result

- `execute(input, { signal })` may return `Promise<any>`; **the result format is not specified**. The explainer's examples use the MCP shape `{ content: [{ type: "text", text }] }`.
  `executeTool()` resolves with `JSON.stringify(result)`.
- `input` is always an object. `signal` is created by the browser and aborted on `toolcancel` / caller abort.
- **Failure:** a rejecting `execute` makes the execution fail; the caller gets an `UnknownError` DOMException. The agent never sees the message.
  Consequence for this package: business errors (validation, authorization, `Response::error`) are returned as a normal result `{ content: [...], isError: true }`; only aborts and programming errors reject.
- `executeTool` across top-level documents fails with `UnknownError` (#227).
- `getTools({ fromOrigins })` is for in-page agents. Cross-origin frames need `allow="tools"` **and** `exposedTo` on the tool.

## 4. Permissions policy

Policy-controlled feature **`tools`**, default allowlist `'self'` (top level and same-origin iframes).
Cross-origin iframe: `<iframe allow="tools">`. Disable: `Permissions-Policy: tools=()` (applies to all descendants).
Behavior of declarative registration when the policy is off is TBD (#182).

## 5. Declarative API (`declarative-api-explainer.md`)

| Element                                    | Meaning                                                                                                                      |
|--------------------------------------------|------------------------------------------------------------------------------------------------------------------------------|
| `<form toolname>`                          | tool name                                                                                                                    |
| `tooldescription`                          | description                                                                                                                  |
| `toolautosubmit` (boolean)                 | the agent may submit after filling; without it the submit button is focused and the agent tells the user to check and submit |
| `name` on a control                        | property name in the schema                                                                                                  |
| `toolparamdescription` on a control        | property `description`                                                                                                       |
| `SubmitEvent.agentInvoked`                 | handlers can detect agent submits                                                                                            |
| `SubmitEvent.respondWith(Promise<any>)`    | answer for the agent; **`preventDefault()` must come first**; the form does not navigate                                     |
| `:tool-form-active`, `:tool-submit-active` | pseudo-classes for a form being filled by an agent / its submit button                                                       |
| `toolactivated` / `toolcancel`             | events on `ModelContext` (target for declarative tools: open, #126)                                                          |

Inserting/removing/changing the attributes registers/unregisters/replaces the tool. A form reset or a changed declaration cancels running calls.

**Open / TBD:** the input schema synthesis is **not specified** (`index.bs`: TODO; Chromium implements a loose version); response after navigation (#135, proposal: first `application/ld+json` of the target page);
whether declarative tools appear in `getTools()/executeTool()`; `outputSchema` for declarative tools (#9).

## 6. Open items

| Topic                                  | Issue         | Handling in this package                                                |
|----------------------------------------|---------------|-------------------------------------------------------------------------|
| User confirmation / elicitation        | #165, #50     | client confirm hook, `consequentialHint`, optional server token         |
| `outputSchema`                         | #9            | no spec field; flag `features.output_schema` adds an extra member (off) |
| Streaming                              | #82           | generator/SSE output aggregated                                         |
| Progress                               | –             | notifications dropped                                                   |
| Multimodal                             | #41, #86, #81 | text fallback with MIME type and size                                   |
| Cross-document response                | #135          | forms answer through `respondWith`                                      |
| Declarative errors with policy off     | #182          | –                                                                       |
| Input/output validation by the browser | #92           | always validated on the server                                          |
| `native-agent` exposure keyword        | README        | not supported                                                           |
| `updateTool`, lazy schemas, groups     | #167, #255    | re-register via `abort()` + `registerTool`                              |
| Top-level `executeTool`                | #227          | –                                                                       |
| Length limits for titles/descriptions  | #73           | configurable limits (256 / 4096)                                        |

## 7. Security and privacy (spec) and consequences

- **Prompt injection (metadata and outputs):** `untrustedContentHint` for resource tools and `#[WebMcp(untrusted: true)]`.
- **Misrepresentation of intent:** `consequentialHint` from `#[IsDestructive]`.
- **Over-parameterization / privacy leak:** the package forwards only the tool's schema; review schemas for personal data.
- **Same-origin violations:** spec section still TODO; `exposedTo` only from a config allowlist.
- **Private browsing:** user-agent responsibility. **Permissions-Policy `tools=()`** is the mitigation for compromised scripts.

## 8. Browser status (`implementation-status.md`, 2026-10-04)

Chrome: origin trial (149), local `chrome://flags/#enable-webmcp-testing`. Edge: origin trial (150). Brave, ChatGPT Desktop: experimental/supported.
Firefox/Safari: standards-position issues only. Meta Ray-Ban: `document.modelContext.registerTool()` announced.
All name `document.modelContext`.

## 9. Observed in Chrome 154 (flag `--enable-features=WebMCPTesting`, 2026-10)

- `document.modelContext` is available; declarative `<form toolname>` tools show up in `getTools()` next to imperative ones.
- `executeTool(tool, input)` **requires `input` as a JSON string** ("Failed to parse input arguments" for an object or no input), the IDL says `object`. The result is the JSON string of the `execute` return value.
- `Permissions-Policy: tools=()` makes `getTools()` reject with `NotAllowedError`; registration is rejected the same way.
- Agent-invoked submits of a `toolautosubmit` form reach `submit` handlers with `agentInvoked`; `respondWith()` answers without navigation.
