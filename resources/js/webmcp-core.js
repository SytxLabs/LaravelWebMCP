/**
 * sytxlabs/laravel-webmcp browser runtime (core). ESM, no dependencies.
 *
 * Import it through webmcp.js: that file is the public entry point and only re-exports this one.
 * The core has ONE url (no query string) so the page holds exactly one copy of the runtime state, even when the entry point is loaded with a cache-busting `?v=` by the Blade tag and without one by the adapters.
 *
 * Registers the tools of a server-rendered manifest on `document.modelContext` (W3C WebMCP) and runs them against the Laravel backend (Session routes or the Mcp::web() endpoint in Bridge mode).
 *
 *  - Feature detection: `"modelContext" in document`. Without it every call is a no-op.
 *  - Unregistration happens ONLY through AbortController.abort() (the spec has no unregisterTool()).
 *  - registerTool() promises reject with the abort reason when a tool is unregistered; that is expected.
 *  - Failures are resolved results `{ content, isError: true }` by default: a rejected execute() reaches the agent as a bare UnknownError without the message.
 *
 * @ts-check
 */

/// <reference types="webmcp-types" />

const MANIFEST_VERSION = 1;
const EVENT_PREFIX = 'webmcp:';

/**
 * @typedef {{ tool: string, title: string, description: string, arguments: Record<string, unknown>, annotations?: Record<string, boolean> }} ConfirmRequest
 * @typedef {{
 *   confirm: (request: ConfirmRequest) => boolean | Promise<boolean>,
 *   resolveModelContext: null | (() => WebMCP.ModelContext | null | undefined),
 *   legacyNavigator: boolean,
 *   refreshOnFocus: boolean,
 *   refreshMinInterval: number,
 *   bearer: null | (() => string | null | Promise<string | null>),
 *   errors: null | 'result' | 'reject',
 *   fetch: null | typeof fetch,
 * }} Config
 */

/** @type {Config} */
const config = {
    confirm: defaultConfirm,
    resolveModelContext: null,
    legacyNavigator: true,
    refreshOnFocus: true,
    refreshMinInterval: 30000,
    bearer: null,
    errors: null,
    fetch: null,
};

/**
 * @typedef {{ controller: AbortController, hash: string, def: any }} ToolRecord
 * @typedef {(def: any, args: Record<string, unknown>, signal: AbortSignal | undefined) => unknown} Runner
 * @typedef {{ slug: string, payload: any, csrf: string | null, tools: Map<string, ToolRecord>, refreshing: Promise<any> | null, lastRefresh: number, runner?: Runner, skipped?: Set<string>, definitions?: any[], embeddedText?: string }} ServerState
 */

/** @type {Map<string, ServerState>} */
const servers = new Map();
let unsupportedReported = false;
let requestId = 0;

// ---------------------------------------------------------------------------------------------
// events
// ---------------------------------------------------------------------------------------------

/**
 * @param {string} name
 * @param {Record<string, unknown>} [detail]
 */
function emit(name, detail = {}) {
    if (typeof document === 'undefined') {
        return;
    }
    document.dispatchEvent(new CustomEvent(EVENT_PREFIX + name, { detail }));
}

/**
 * Subscribe to a runtime event: invoked, succeeded, failed, registered, unregistered, refreshed, error, unsupported.
 * @param {string} name
 * @param {(event: CustomEvent) => void} listener
 */
function on(name, listener) {
    document.addEventListener(EVENT_PREFIX + name, /** @type {EventListener} */ (listener));
}

/**
 * @param {string} name
 * @param {(event: CustomEvent) => void} listener
 */
function off(name, listener) {
    document.removeEventListener(EVENT_PREFIX + name, /** @type {EventListener} */ (listener));
}

// ---------------------------------------------------------------------------------------------
// WebMCP access
// ---------------------------------------------------------------------------------------------

/**
 * @returns {{ mc: WebMCP.ModelContext, legacy: boolean } | null}
 */
function getModelContext() {
    if (typeof config.resolveModelContext === 'function') {
        const resolved = config.resolveModelContext();
        if (resolved) {
            return { mc: resolved, legacy: false };
        }
    }
    if (typeof document !== 'undefined' && 'modelContext' in document && document.modelContext) {
        return { mc: document.modelContext, legacy: false };
    }
    const nav = typeof navigator !== 'undefined' ? /** @type {any} */ (navigator) : null;
    if (config.legacyNavigator && nav && nav.modelContext) {
        return { mc: nav.modelContext, legacy: true };
    }
    return null;
}

function reportUnsupported() {
    if (unsupportedReported) {
        return;
    }
    unsupportedReported = true;
    emit('unsupported', {});
}

// ---------------------------------------------------------------------------------------------
// registration
// ---------------------------------------------------------------------------------------------

/**
 * @param {any} def
 * @returns {string}
 */
function hashOf(def) {
    return JSON.stringify(def);
}

/**
 * @param {ServerState} state
 * @param {any} def
 * @param {{ mc: any, legacy: boolean }} ctx
 */
function registerTool(state, def, ctx) {
    const controller = new AbortController();
    /** @type {ToolRecord} */
    const record = { controller, hash: hashOf(def), def };
    state.tools.set(def.name, record);

    /** @type {any} */
    const tool = {name: def.name, description: def.description, execute: (/** @type {any} */ args, /** @type {any} */ options) => execute(state, def, args ?? {}, options)};
    if (def.title) {
        tool.title = def.title;
    }
    if (def.inputSchema) {
        tool.inputSchema = def.inputSchema;
    }
    if (def.annotations && Object.keys(def.annotations).length > 0) {
        tool.annotations = { ...def.annotations };
    }

    /** @type {any} */
    const options = { signal: controller.signal };
    if (Array.isArray(def.exposedTo) && def.exposedTo.length > 0) {
        options.exposedTo = [...def.exposedTo];
    }
    if (ctx.legacy) {
        // Pre-spec implementations may ignore `signal`; unregister explicitly where they offer it.
        controller.signal.addEventListener('abort', () => {
            try {
                ctx.mc.unregisterTool?.(def.name);
            } catch {
                // ignore: best effort for a non-spec API
            }
        }, { once: true });
    }

    /** @type {Promise<unknown>} */
    let pending;
    try {
        pending = Promise.resolve(ctx.mc.registerTool(tool, options));
    } catch (error) {
        pending = Promise.reject(error);
    }
    pending.then(
        () => {
            if (!controller.signal.aborted) {emit('registered', { server: state.slug, tool: def.name });}
        },
        (error) => {
            if (controller.signal.aborted) {
                return;
            }
            if (state.tools.get(def.name) === record) {
                state.tools.delete(def.name);
            }
            emit('error', {server: state.slug, tool: def.name, kind: 'registration', name: error && error.name ? error.name : 'Error', message: error && error.message ? error.message : String(error), error});
        },
    );
}

/**
 * @param {ServerState} state
 * @param {string} name
 */
function removeTool(state, name) {
    const record = state.tools.get(name);

    if (!record) {
        return;
    }
    state.tools.delete(name);
    record.controller.abort();
    emit('unregistered', { server: state.slug, tool: name });
}

/**
 * @returns {Set<string>}
 */
function declaredFormNames() {
    if (typeof document === 'undefined') {
        return new Set();
    }
    return new Set([...document.querySelectorAll('form[toolname]')].map((form) => form.getAttribute('toolname') || ''));
}

/**
 * @param {ServerState} state
 * @param {any[]} definitions
 * @returns {{ added: string[], removed: string[] }}
 */
function reconcile(state, definitions) {
    state.definitions = definitions;
    const ctx = getModelContext();
    if (!ctx) {
        reportUnsupported();
        return { added: [], removed: [] };
    }
    const declared = declaredFormNames();
    const next = new Map(definitions.filter((def) => {
        if (!declared.has(def.name)) return true;
        if (!state.skipped) {state.skipped = new Set();}
        if (!state.skipped.has(def.name)) {
            state.skipped.add(def.name);
            emit('skipped', { server: state.slug, tool: def.name, reason: 'declarative-form' });
        }
        return false;
    }).map((def) => [def.name, def]));
    const removed = [];
    const added = [];

    for (const [name, record] of [...state.tools]) {
        const def = next.get(name);
        if (!def || record.hash !== hashOf(def)) {
            removeTool(state, name);
            removed.push(name);
        }
    }
    for (const [name, def] of next) {
        if (!state.tools.has(name)) {
            registerTool(state, def, ctx);
            added.push(name);
        }
    }
    return { added, removed };
}

/**
 * @param {ServerState} state
 */
function removeAll(state) {
    for (const name of [...state.tools.keys()]) {
        removeTool(state, name);
    }
}

/**
 * @param {string} slug
 * @returns {any}
 */
function readEmbedded(slug) {
    const element = document.getElementById('webmcp-manifest-' + slug);
    if (!element) return null;
    try {
        return JSON.parse(element.textContent || '');
    } catch {
        return null;
    }
}

/**
 * Register the tools of a server. Accepts the server slug (reads the embedded manifest) or a payload object. Registering an already registered server replaces it.
 *
 * @param {string | any} server
 * @returns {{ server: string, supported: boolean, tools: string[] } | null}
 */
function register(server) {
    const payload = typeof server === 'string' ? readEmbedded(server) : server;
    if (!payload || payload.version !== MANIFEST_VERSION || typeof payload.server !== 'string' || !Array.isArray(payload.tools)) {
        emit('error', { server: typeof server === 'string' ? server : null, kind: 'manifest', message: 'Missing or invalid WebMCP manifest.' });
        return null;
    }

    const existing = servers.get(payload.server);

    if (existing) {
        removeAll(existing);
    }
    /** @type {ServerState} */
    const state = {slug: payload.server, payload, csrf: typeof payload.csrf === 'string' ? payload.csrf : null, tools: new Map(), refreshing: null, lastRefresh: Date.now()};
    servers.set(state.slug, state);
    reconcile(state, payload.tools);
    return { server: state.slug, supported: getModelContext() !== null, tools: [...state.tools.keys()] };
}

/**
 * Turns any tool return value into the result shape agents get: strings become text, objects JSON text, nothing becomes "Done.". A value that already is `{ content: [...] }` is passed through.
 *
 * @param {unknown} value
 * @returns {Result}
 */
function toResult(value) {
    if (value && typeof value === 'object' && Array.isArray(/** @type {any} */ (value).content)) {
        return /** @type {Result} */ (value);
    }

    if (value === undefined || value === null) {
        return { content: [{ type: 'text', text: 'Done.' }] };
    }

    if (typeof value === 'string') {
        return { content: [{ type: 'text', text: value }] };
    }

    if (typeof value === 'number' || typeof value === 'boolean' || typeof value === 'bigint') {
        return { content: [{ type: 'text', text: String(value) }] };
    }

    try {
        return { content: [{ type: 'text', text: JSON.stringify(value) }] };
    } catch {
        return { content: [{ type: 'text', text: String(value) }] };
    }
}

/**
 * Registers tools that are not part of a server manifest and run in the browser or through a framework (Livewire components, Alpine elements). Registration, abort-only unregistration, confirm hook, events
 * and the error convention are shared with server tools. The runner executes a call: `(def, args, signal) => result | value` (throw to fail, AbortError when the signal aborts).
 *
 * @param {string} id unique scope id, e.g. "livewire:abc123"
 * @param {any[]} definitions tool definitions: { name, description, title?, inputSchema?, annotations?, confirm?, exposedTo? }
 * @param {{ runner: Runner, errors?: 'result' | 'reject' }} options
 * @returns {{ id: string, sync: (definitions: any[]) => { added: string[], removed: string[] }, dispose: () => void, tools: () => string[] }}
 */
function registerScope(id, definitions, options) {
    const existing = servers.get(id);

    if (existing) {
        removeAll(existing);
    }

    /** @type {ServerState} */
    const state = {slug: id, payload: { version: MANIFEST_VERSION, server: id, errors: options.errors || null, tools: [] }, csrf: null, tools: new Map(), refreshing: null, lastRefresh: Date.now(), runner: options.runner};
    servers.set(id, state);
    reconcile(state, definitions);
    return {
        id,
        sync: (next) => (servers.get(id) === state ? reconcile(state, next) : { added: [], removed: [] }),
        dispose: () => {
            if (servers.get(id) === state) {
                removeAll(state);
                servers.delete(id);
            }
        },
        tools: () => [...state.tools.keys()],
    };
}

/**
 * Abort (unregister) every tool of a server.
 * @param {string} slug
 */
function unregister(slug) {
    const state = servers.get(slug);
    if (!state) {
        return;
    }
    removeAll(state);
    servers.delete(slug);
}

/**
 * Re-fetch the manifest for the current user and reconcile: removed tools are aborted, new ones registered. Runs on login/logout (`webmcp:auth-changed`), on 401/404/419 answers and when the tab regains focus.
 *
 * @param {string} [slug] one server, or all when omitted
 * @returns {Promise<any>}
 */
function refresh(slug) {
    if (slug === undefined) {
        return Promise.all([...servers.keys()].map((name) => refresh(name)));
    }
    const state = servers.get(slug);
    if (!state) {
        return Promise.resolve(null);
    }
    if (!state.refreshing) {
        state.refreshing = doRefresh(state).finally(() => {state.refreshing = null;});
    }
    return state.refreshing;
}

/**
 * @param {ServerState} state
 */
async function doRefresh(state) {
    const url = state.payload.endpoints && state.payload.endpoints.manifest;
    if (!url || getModelContext() === null) {
        return null;
    }
    let response;
    try {
        response = await http()(url, { method: 'GET', credentials: 'same-origin', headers: baseHeaders(state) });
    } catch (error) {
        emit('error', { server: state.slug, kind: 'refresh', message: messageOf(error), error });
        return null;
    }

    state.lastRefresh = Date.now();
    if ([401, 403, 404].includes(response.status)) {
        const removed = [...state.tools.keys()];
        removeAll(state);
        emit('refreshed', { server: state.slug, status: response.status, added: [], removed });
        return { added: [], removed };
    }

    if (!response.ok) {
        emit('error', { server: state.slug, kind: 'refresh', message: `Manifest refresh failed (${response.status}).` });
        return null;
    }

    /** @type {any} */
    let body;
    try {
        body = await response.json();
    } catch {
        emit('error', { server: state.slug, kind: 'refresh', message: 'Manifest refresh returned invalid JSON.' });
        return null;
    }

    if (typeof body.csrf === 'string') {
        state.csrf = body.csrf;
    }
    state.payload = { ...state.payload, ...body, endpoints: { ...state.payload.endpoints, ...(body.endpoints || {}) } };
    const result = reconcile(state, Array.isArray(body.tools) ? body.tools : []);
    emit('refreshed', { server: state.slug, status: response.status, ...result });

    return result;
}

// ---------------------------------------------------------------------------------------------
// execution
// ---------------------------------------------------------------------------------------------

/**
 * @typedef {{ content: Array<{ type: string, text?: string }>, isError?: boolean, [key: string]: unknown }} Result
 */

/**
 * @param {string} message
 * @returns {Result}
 */
function errorResult(message) {
    return { content: [{ type: 'text', text: message }], isError: true };
}

/**
 * @param {unknown} error
 * @returns {string}
 */
function messageOf(error) {
    return error && /** @type {any} */ (error).message ? String(/** @type {any} */ (error).message) : 'The request failed.';
}

/**
 * @param {unknown} error
 * @param {AbortSignal | undefined} signal
 */
function isAbort(error, signal) {
    return (signal && signal.aborted) || (error && /** @type {any} */ (error).name === 'AbortError');
}

/**
 * @param {Result} result
 * @returns {string}
 */
function textOf(result) {
    return (result.content || []).map((item) => item.text || '').join('\n');
}

/**
 * @param {ServerState} state
 * @param {any} def
 * @param {Record<string, unknown>} args
 * @param {{ signal?: AbortSignal } | undefined} options
 */
async function execute(state, def, args, options) {
    const signal = options && options.signal ? options.signal : undefined;
    const started = Date.now();
    const detail = { server: state.slug, tool: def.name, arguments: args };

    emit('invoked', detail);

    /** @type {Result} */
    let outcome;

    try {
        if (def.confirm && !(await askConfirm(def, args))) {
            outcome = errorResult('The user declined this action.');
            emit('failed', { ...detail, reason: 'declined', duration: Date.now() - started });
            return finalize(state, outcome);
        }
        if (state.runner) {
            outcome = toResult(await state.runner(def, args, signal));
        } else {
            outcome = def.mode === 'bridge' ? await callBridge(state, def, args, signal) : await callSession(state, def, args, signal);
        }
    } catch (error) {
        if (isAbort(error, signal)) {
            emit('failed', { ...detail, reason: 'aborted', duration: Date.now() - started });
            throw error;
        }
        outcome = errorResult(messageOf(error));
    }
    emit(outcome.isError ? 'failed' : 'succeeded', { ...detail, reason: outcome.isError ? 'error' : undefined, duration: Date.now() - started, result: outcome });
    return finalize(state, outcome);
}

/**
 * @param {ServerState} state
 * @param {Result} outcome
 */
function finalize(state, outcome) {
    const mode = config.errors || state.payload.errors || 'result';
    if (outcome.isError && mode === 'reject') {throw new Error(textOf(outcome) || 'The tool failed.');}
    return outcome;
}

/**
 * @param {any} def
 * @param {Record<string, unknown>} args
 * @returns {Promise<boolean>}
 */
async function askConfirm(def, args) {
    return confirmRequest({tool: def.name, title: def.title || def.name, description: def.description, arguments: args, annotations: def.annotations});
}

/**
 * Runs the confirm hook. A hook that throws counts as "no".
 *
 * @param {ConfirmRequest} request
 * @returns {Promise<boolean>}
 */
async function confirmRequest(request) {
    try {
        return Boolean(await config.confirm(request));
    } catch {
        return false;
    }
}

/**
 * @param {ServerState} state
 * @returns {Record<string, string>}
 */
function baseHeaders(state) {
    return {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', [state.payload.header || 'X-WebMCP']: '1'};
}

/**
 * The fetch every request of this runtime goes through. It only talks to the page's own origin: endpoint
 * URLs come from JSON and attributes in the DOM, and DOM that an attacker managed to inject (stored HTML
 * injection) must not be able to make us send the CSRF token or credentials somewhere else.
 *
 * @returns {(url: string, init?: RequestInit) => Promise<Response>}
 */
function http() {
    const send = config.fetch || globalThis.fetch.bind(globalThis);
    return (url, init) => {
        assertSameOrigin(url);
        return send(url, init);
    };
}

/**
 * @param {string} url
 */
function assertSameOrigin(url) {
    if (typeof location === 'undefined') {
        return;
    }
    let target;
    try {
        target = new URL(String(url), location.href);
    } catch {
        throw new Error('Invalid URL.');
    }
    if (target.origin !== location.origin) {
        throw new Error('Cross-origin requests are not allowed.');
    }
}

/**
 * @param {Response} response
 * @returns {Promise<any>}
 */
async function readJson(response) {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

/**
 * @param {ServerState} state
 * @param {any} def
 * @param {Record<string, unknown>} args
 * @param {AbortSignal | undefined} signal
 * @param {string | null} [confirmation]
 * @param {boolean} [retried]
 * @returns {Promise<Result>}
 */
async function callSession(state, def, args, signal, confirmation = null, retried = false) {
    const endpoints = state.payload.endpoints || {};
    const template = def.kind === 'tool' ? endpoints.tools : endpoints.resources;

    if (!template) {
        return errorResult('This tool has no endpoint.');
    }

    /** @type {{ arguments: Record<string, unknown>, confirmation?: string }} */
    const body = { arguments: args };

    if (confirmation) {
        body.confirmation = confirmation;
    }

    /** @type {Record<string, string>} */
    const headers = { ...baseHeaders(state), 'Content-Type': 'application/json' };
    const csrf = state.csrf || metaCsrf();
    if (csrf) {
        headers['X-CSRF-TOKEN'] = csrf;
    }
    const response = await http()(template.replace('{name}', encodeURIComponent(def.name)), {method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify(body), signal});
    if (response.status === 419 && !retried) {
        await refresh(state.slug);
        return callSession(state, def, args, signal, confirmation, true);
    }

    if (!response.ok) {
        return failureResult(state, response, await readJson(response));
    }
    const json = await readJson(response);
    if (json && json.confirmation && json.confirmation.required && json.confirmation.token && !confirmation) {
        return callSession(state, def, args, signal, json.confirmation.token, retried);
    }
    if (!json || !Array.isArray(json.content)) {
        return errorResult('The server returned an unexpected response.');
    }
    return json;
}

/**
 * @param {ServerState} state
 * @param {Response} response
 * @param {any} json
 * @returns {Result}
 */
function failureResult(state, response, json) {
    if ([401, 404, 419].includes(response.status)) {
        void refresh(state.slug);
    }
    return errorResult(failureMessage(response, json));
}

/**
 * Agent-readable text for a failed HTTP answer of the Session routes.
 *
 * @param {Response} response
 * @param {any} json
 * @returns {string}
 */
function failureMessage(response, json) {
    const serverMessage = json && json.error && typeof json.error.message === 'string' ? json.error.message : null;
    switch (response.status) {
        case 401:
            return 'You are not signed in.';
        case 419:
            return 'Your session expired. Reload the page and try again.';
        case 404:
            return 'This tool is no longer available.';
        case 403:
            return serverMessage || 'You are not allowed to do this.';
        case 429: {
            const wait = response.headers && response.headers.get ? response.headers.get('Retry-After') : null;
            return wait ? `Too many requests. Try again in ${wait} seconds.` : 'Too many requests. Try again later.';
        }
        case 400:
        case 413:
        case 415:
            return serverMessage || 'The request was rejected.';
        default:
            return 'The server could not complete the request.';
    }
}

/**
 * POST a tool call to a Session route outside of a registered server (declarative forms). Same headers and
 * error mapping as server tools; CSRF comes from the argument or the page's csrf-token meta tag.
 *
 * @param {string} url
 * @param {Record<string, unknown>} args
 * @param {{ csrf?: string | null, header?: string, signal?: AbortSignal, confirmation?: string | null }} [options]
 * @returns {Promise<Result>}
 */
async function postTool(url, args, options = {}) {
    /** @type {Record<string, string>} */
    const headers = {Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', [options.header || 'X-WebMCP']: '1'};
    const csrf = options.csrf || metaCsrf();
    if (csrf) {
        headers['X-CSRF-TOKEN'] = csrf;
    }
    const body = options.confirmation ? { arguments: args, confirmation: options.confirmation } : { arguments: args };
    let response;
    try {
        response = await http()(url, { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify(body), signal: options.signal });
    } catch (error) {
        if (isAbort(error, options.signal)) {
            throw error;
        }
        return errorResult(messageOf(error));
    }
    const json = await readJson(response);
    if (!response.ok) {
        return errorResult(failureMessage(response, json));
    }
    if (json && json.confirmation && json.confirmation.required && json.confirmation.token && !options.confirmation) {
        return postTool(url, args, { ...options, confirmation: json.confirmation.token });
    }
    return json && Array.isArray(json.content) ? json : errorResult('The server returned an unexpected response.');
}

// ---- Bridge ---------------------------------------------------------------------------------

/**
 * @param {ServerState} state
 * @param {any} def
 * @param {Record<string, unknown>} args
 * @param {AbortSignal | undefined} signal
 * @param {string | null} [confirmation]
 * @param {boolean} [retried]
 * @returns {Promise<Result>}
 */
async function callBridge(state, def, args, signal, confirmation = null, retried = false) {
    const endpoint = state.payload.endpoints && state.payload.endpoints.bridge;
    if (!endpoint || !def.bridge) {
        return errorResult('This tool has no Bridge endpoint.');
    }
    const params = def.bridge.method === 'tools/call' ? { name: def.bridge.name, arguments: args } : { uri: composeUri(state, def.bridge, args) };
    /** @type {Record<string, string>} */
    const headers = {...baseHeaders(state), Accept: 'application/json, text/event-stream', 'Content-Type': 'application/json'};
    if (confirmation) {
        headers[(state.payload.confirmation && state.payload.confirmation.header) || 'X-WebMCP-Confirmation'] = confirmation;
    }
    let credentials = /** @type {RequestCredentials} */ ('same-origin');
    if (typeof config.bearer === 'function') {
        const token = await config.bearer();
        if (token) {
            headers.Authorization = 'Bearer ' + token;
            credentials = 'omit';
        }
    }
    if (credentials === 'same-origin') {
        const xsrf = cookie('XSRF-TOKEN');
        const csrf = xsrf || state.csrf || metaCsrf();
        if (csrf) {
            headers[xsrf ? 'X-XSRF-TOKEN' : 'X-CSRF-TOKEN'] = csrf;
        }
    }
    const response = await http()(endpoint, {method: 'POST', credentials, headers, body: JSON.stringify({ jsonrpc: '2.0', id: ++requestId, method: def.bridge.method, params }), signal});
    if (response.status === 419 && !retried) {
        await refresh(state.slug);
        return callBridge(state, def, args, signal, confirmation, true);
    }
    const message = await readRpc(response);
    if (response.status === 428 && message && message.error && message.error.data && message.error.data.confirmation && !confirmation) {
        return callBridge(state, def, args, signal, message.error.data.confirmation.token, retried);
    }
    if (!response.ok && !(message && (message.result || message.error))) {
        return failureResult(state, response, null);
    }
    if (!message) {
        return errorResult('The server returned an unexpected response.');
    }
    if (message.error) {
        if (response.status === 401 || response.status === 419) {
            return failureResult(state, response, null);
        }
        if (response.status === 403 && /not available/i.test(String(message.error.message))) {
            void refresh(state.slug);
        }
        return errorResult(String(message.error.message || 'The request failed.'));
    }
    return def.bridge.method === 'tools/call' ? mapToolResult(message.result) : mapResourceResult(message.result);
}

/**
 * @param {{ method: string, template?: string, uri?: string, generic?: boolean }} bridge
 * @param {Record<string, unknown>} args
 * @param {ServerState} state
 * @returns {string}
 */
function composeUri(state, bridge, args) {
    if (bridge.generic) {
        if (typeof args.uri !== 'string') {
            throw new Error('Argument [uri] must be a string.');
        }
        return args.uri;
    }
    if (bridge.uri) {
        return bridge.uri;
    }
    const encode = Boolean(state.payload.encodeVariables);
    return String(bridge.template).replace(/\{(\w+)\}/g, (_match, name) => {
        const value = args[name];
        if (typeof value !== 'string' && typeof value !== 'number') {
            throw new Error(`Missing or invalid argument [${name}].`);
        }
        return encode ? encodeURIComponent(String(value)) : String(value);
    });
}

/**
 * Reads a JSON-RPC message from a JSON or SSE (generator tool) response. Notifications are dropped.
 *
 * @param {Response} response
 * @returns {Promise<any>}
 */
async function readRpc(response) {
    if (!(response.headers && response.headers.get ? response.headers.get('content-type') || '' : '').includes('text/event-stream')) return readJson(response);
    return parseSse(await readStream(response) || '').filter((message) => message && (message.result || message.error)).pop() || null;
}

/**
 * @param {Response} response
 * @returns {Promise<string>}
 */
async function readStream(response) {
    if (!response.body || typeof response.body.getReader !== 'function') {
        return response.text();
    }
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let text = '';
    for (;;) {
        const { done, value } = await reader.read();
        if (done) {
            break;
        }
        text += decoder.decode(value, { stream: true });
    }
    return text + decoder.decode();
}

/**
 * @param {string} text
 * @returns {any[]}
 */
function parseSse(text) {
    const messages = [];
    for (const block of text.split(/\r?\n\r?\n/)) {
        const data = block.split(/\r?\n/).filter((line) => line.startsWith('data:')).map((line) => line.slice(5).replace(/^ /, '')).join('\n');
        if (!data) {
            continue;
        }
        try {
            messages.push(JSON.parse(data));
        } catch {
            // ignore malformed event
        }
    }
    return messages;
}

/**
 * @param {any} result
 * @returns {Result}
 */
function mapToolResult(result) {
    const items = Array.isArray(result && result.content) ? result.content : [];
    const content = items.map((/** @type {any} */ item) => {
        if (item && item.type === 'text') {
            return { type: 'text', text: String(item.text ?? '') };
        }
        return { type: 'text', text: `[${item && item.type ? item.type : 'binary'} content omitted: ${(item && item.mimeType) || 'unknown type'}]` };
    });
    /** @type {Result} */
    const envelope = { content: content.length > 0 ? content : [{ type: 'text', text: '' }] };
    if (result && result.isError === true) {
        envelope.isError = true;
    }
    return envelope;
}

/**
 * @param {any} result
 * @returns {Result}
 */
function mapResourceResult(result) {
    const items = Array.isArray(result && result.contents) ? result.contents : [];
    const content = items.map((/** @type {any} */ item) => (item && typeof item.text === 'string' ? { type: 'text', text: item.text } : { type: 'text', text: `[binary content omitted: ${(item && item.mimeType) || 'application/octet-stream'}]` }));
    return { content: content.length > 0 ? content : [{ type: 'text', text: '' }] };
}

// ---------------------------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------------------------

/**
 * @param {string} name
 * @returns {string | null}
 */
function cookie(name) {
    if (typeof document === 'undefined' || !document.cookie) {
        return null;
    }
    for (const part of document.cookie.split('; ')) {
        const index = part.indexOf('=');
        if (index > 0 && part.slice(0, index) === name) {
            try {
                return decodeURIComponent(part.slice(index + 1));
            } catch {
                return null;
            }
        }
    }
    return null;
}

/**
 * @returns {string | null}
 */
function metaCsrf() {
    if (typeof document === 'undefined') {
        return null;
    }
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : null;
}

// ---------------------------------------------------------------------------------------------
// confirmation
// ---------------------------------------------------------------------------------------------

/**
 * Default confirm hook: window.confirm. Replace it with WebMcp.configure({ confirm }) once the spec defines a mechanism (#165, #50) or to show your own dialog. Without a UI the answer is "no".
 *
 * @param {ConfirmRequest} request
 * @returns {boolean}
 */
function defaultConfirm(request) {
    if (typeof window === 'undefined' || typeof window.confirm !== 'function') {
        return false;
    }
    return window.confirm(`Allow the assistant to run "${request.title}"?\n\n${request.description}`);
}

/**
 * Builds a confirm hook that shows a native <dialog>. All text is inserted with textContent (no HTML).
 *
 * @param {{ title?: (request: ConfirmRequest) => string, confirmLabel?: string, cancelLabel?: string }} [labels]
 * @returns {(request: ConfirmRequest) => Promise<boolean>}
 */
function createDialogConfirm(labels = {}) {
    return (request) => new Promise((resolve) => {
        const dialog = document.createElement('dialog');
        dialog.setAttribute('data-webmcp-confirm', '');
        const heading = document.createElement('h2');
        heading.textContent = labels.title ? labels.title(request) : `Run "${request.title}"?`;
        const body = document.createElement('p');
        body.textContent = request.description;
        const form = document.createElement('form');
        form.method = 'dialog';
        const cancel = document.createElement('button');
        cancel.value = 'cancel';
        cancel.textContent = labels.cancelLabel || 'Cancel';
        const confirmButton = document.createElement('button');
        confirmButton.value = 'confirm';
        confirmButton.textContent = labels.confirmLabel || 'Allow';
        form.append(cancel, confirmButton);
        dialog.append(heading, body, form);
        document.body.append(dialog);
        dialog.addEventListener('close', () => {
            const accepted = dialog.returnValue === 'confirm';
            dialog.remove();
            resolve(accepted);
        });
        dialog.showModal();
    });
}

// ---------------------------------------------------------------------------------------------
// public API
// ---------------------------------------------------------------------------------------------

/**
 * @param {Partial<Config>} options
 */
function configure(options) {
    Object.assign(config, options);
    if ('resolveModelContext' in options || 'legacyNavigator' in options) {resync();}
}

/**
 * Re-run the registration of every known server and scope against the ModelContext that is available now.
 * Tools that are already registered stay; missing ones are registered. Called by configure() when the
 * ModelContext source changes, and callable when an API appears late.
 */
function resync() {
    for (const state of servers.values()) {reconcile(state, state.definitions || (Array.isArray(state.payload.tools) ? state.payload.tools : []));}
}

const WebMcp = {
    version: MANIFEST_VERSION,
    register,
    registerScope,
    unregister,
    refresh,
    configure,
    resync,
    on,
    off,
    createDialogConfirm,
    toResult,
    confirm: confirmRequest,
    postTool,
    isSupported: () => getModelContext() !== null,
    servers: () => [...servers.keys()],
    tools: (/** @type {string} */ slug) => [...(servers.get(slug)?.tools.keys() ?? [])],
    autoRegister: autoRegisterLocal,
    resetForTests: resetLocal,
};

/**
 * Registers every manifest embedded in the page (`<script type="application/json" data-webmcp-manifest>`).
 */
function autoRegisterLocal() {
    if (typeof document === 'undefined') {
        return;
    }
    syncEmbedded();
}

/**
 * Makes the registered servers match the manifests embedded in the page right now. Runs at load and after every SPA navigation (wire:navigate, Turbo):
 * servers whose manifest left the page are unregistered, new or changed manifests are registered, identical ones are left alone. Servers registered by hand (register(payload)) are not touched.
 */
function syncEmbedded() {
    if (typeof document === 'undefined') {
        return;
    }
    /** @type {Map<string, { text: string, payload: any }>} */
    const present = new Map();
    for (const element of document.querySelectorAll('script[data-webmcp-manifest]')) {
        const text = element.textContent || '';
        try {
            const payload = JSON.parse(text);
            if (payload && typeof payload.server === 'string') {
                present.set(payload.server, { text, payload });
            }
        } catch {
            emit('error', { server: null, kind: 'manifest', message: 'Invalid embedded WebMCP manifest.' });
        }
    }
    for (const [slug, state] of [...servers]) {
        if (state.embeddedText !== undefined && !present.has(slug)) {
            unregister(slug);
        }
    }
    for (const [slug, { text, payload }] of present) {
        if (servers.get(slug)?.embeddedText === text) {
            continue;
        }
        if (register(payload)) {
            const state = servers.get(slug);
            if (state) {
                state.embeddedText = text;
            }
        }
    }
}

function installLifecycleHooks() {
    if (typeof document === 'undefined') {
        return;
    }
    document.addEventListener(EVENT_PREFIX + 'auth-changed', () => {void refresh();});
    for (const name of ['livewire:navigated', 'turbo:load']) {
        document.addEventListener(name, () => {syncEmbedded();});
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible' || !config.refreshOnFocus) return;
        for (const state of servers.values()) {
            if (Date.now() - state.lastRefresh >= config.refreshMinInterval) {void refresh(state.slug);}
        }
    });
}

/**
 * Test hook: forget all state. Not part of the public API.
 * @private
 */
function resetLocal() {
    for (const state of servers.values()) {
        removeAll(state);
    }

    servers.clear();
    unsupportedReported = false;
    requestId = 0;
    config.confirm = defaultConfirm;
    config.resolveModelContext = null;
    config.legacyNavigator = true;
    config.refreshOnFocus = true;
    config.refreshMinInterval = 30000;
    config.bearer = null;
    config.errors = null;
    config.fetch = null;
}

if (typeof window !== 'undefined') {
    installLifecycleHooks();

    if (!(/** @type {any} */ (window).WebMcp)) {
        /** @type {any} */ (window).WebMcp = WebMcp;
    }

    if (!(/** @type {any} */ (globalThis).__WEBMCP_NO_AUTO__)) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', autoRegisterLocal, { once: true });
        } else {
            autoRegisterLocal();
        }
    }
}

export {
    WebMcp,
    register,
    registerScope,
    toResult,
    postTool,
    confirmRequest as confirm,
    unregister,
    refresh,
    configure,
    on,
    off,
    createDialogConfirm,
    autoRegisterLocal as autoRegister,
    resetLocal as resetForTests,
};
export default WebMcp;
