/**
 * Livewire adapter (Livewire 3 and 4) for sytxlabs/laravel-webmcp.
 *
 * Reads the actions that `@webmcpActions` publishes inside a component, registers them as WebMCP tools and runs them with `$wire.$call(method, ...args)`,
 * so Livewire's own pipeline applies (update checksum, method authorization attributes, validation, your policies).
 *
 * Lifecycle: one scope per component instance. It is registered when the component initializes, kept in sync after every update, and aborted when the component is destroyed (removal, `wire:navigate`).
 *
 * Load it before Livewire starts (it waits for `livewire:init`) or after (it picks up existing components).
 */

import { registerScope, toResult } from './webmcp.js';

/** @type {Map<string, { scope: ReturnType<typeof registerScope>, component: any }>} */
const scopes = new Map();
let installed = false;
/** @type {any} */
let livewireRef = null;

/**
 * @param {any} component
 * @returns {any | null}
 */
function readManifest(component) {
    const root = component && component.el;

    if (!root || typeof root.querySelector !== 'function') {
        return null;
    }
    const node = root.querySelector(`script[data-webmcp-livewire][data-webmcp-component="${String(component.id).replace(/["\\]/g, '\\$&')}"]`);
    if (!node) {
        return null;
    }
    try {
        const manifest = JSON.parse(node.textContent || '');
        return manifest && manifest.version === 1 && Array.isArray(manifest.tools) ? manifest : null;
    } catch {
        return null;
    }
}

/**
 * Create, update or remove the component's scope to match what the DOM currently publishes.
 * @param {any} component
 */
function sync(component) {
    const manifest = readManifest(component);
    const entry = scopes.get(component.id);

    if (!manifest || manifest.tools.length === 0) {
        dispose(component.id);
        return;
    }

    if (entry) {
        entry.scope.sync(manifest.tools);
        return;
    }
    scopes.set(component.id, {
        component,
        scope: registerScope('livewire:' + component.id, manifest.tools, {runner: (/** @type {any} */ def, /** @type {any} */ args, /** @type {AbortSignal | undefined} */ signal) => run(component, def, args, signal),}),
    });
}

/**
 * @param {string} id
 */
function dispose(id) {
    const entry = scopes.get(id);

    if (entry) {
        entry.scope.dispose();
        scopes.delete(id);
    }
}

/**
 * Positional arguments in method order. Livewire calls actions positionally; gaps before a given
 * argument are filled with the method's default so later arguments land in the right slot.
 *
 * @param {{ params?: string[], defaults?: Record<string, unknown>, variadic?: string | null }} meta
 * @param {Record<string, unknown>} args
 * @returns {unknown[]}
 */
function positional(meta, args) {
    const params = meta.params || [];
    const defaults = meta.defaults || {};
    let last = -1;

    params.forEach((name, index) => {
        if (args[name] !== undefined) {last = index;}
    });

    /** @type {unknown[]} */
    const out = [];

    params.slice(0, last + 1).forEach((name) => {
        const value = args[name] !== undefined ? args[name] : (name in defaults ? defaults[name] : null);
        if (name === meta.variadic && Array.isArray(value)) {
            out.push(...value);
        } else {
            out.push(value);
        }
    });

    return out;
}

/**
 * @param {any} component
 * @returns {string}
 */
function errorsSnapshot(component) {
    try {
        return JSON.stringify((component.snapshot && component.snapshot.memo && component.snapshot.memo.errors) || {});
    } catch {
        return '{}';
    }
}

/**
 * @param {Record<string, unknown> | null | undefined} errors
 * @returns {string}
 */
function validationText(errors) {
    const messages = Object.values(errors || {}).flatMap((value) => (Array.isArray(value) ? value : [value]));

    return messages.length > 0 ? messages.map(String).join(' ') : 'The given data was invalid.';
}

/**
 * @param {any} error
 * @returns {string}
 */
function describe(error) {
    if (error && error.errors) {
        return validationText(error.errors);
    }

    switch (error && error.status) {
        case 403:
            return 'You are not allowed to do this.';
        case 419:
            return 'Your session expired. Reload the page and try again.';
        case 404:
            return 'This action is no longer available.';
        case 429:
            return 'Too many requests. Try again later.';
        default:
            return error && error.status ? 'The action failed.' : (error && error.message) || 'The action failed.';
    }
}

/**
 * @param {Promise<unknown>} promise
 * @param {AbortSignal | undefined} signal
 * @returns {Promise<unknown>}
 */
function raceAbort(promise, signal) {
    if (!signal) {
        return promise;
    }

    const abortError = () => (signal.reason instanceof Error ? signal.reason : new DOMException('Aborted', 'AbortError'));

    if (signal.aborted) {
        return Promise.reject(abortError());
    }

    return new Promise((resolve, reject) => {
        const onAbort = () => reject(abortError());

        signal.addEventListener('abort', onAbort, { once: true });
        promise.then(
            (value) => {
                signal.removeEventListener('abort', onAbort);
                resolve(value);
            },
            (error) => {
                signal.removeEventListener('abort', onAbort);
                reject(error);
            },
        );
    });
}

/**
 * Livewire swallows a ValidationException thrown inside an action and resolves the call with null. The PHP side (ReportsValidationErrors) sends the messages along as the `webmcpErrors` effect; this listens for it on the next response of the component.
 *
 * @param {any} component
 * @returns {{ errors: () => Record<string, unknown> | null, stop: () => void }}
 */
function captureValidationErrors(component) {
    /** @type {Record<string, unknown> | null} */
    let captured = null;
    let off = null;
    let active = true;
    const wire = component && component.$wire;
    if (wire && typeof wire.$interceptMessage === 'function') {
        off = wire.$interceptMessage((/** @type {{ onSuccess: (fn: (arg: { payload?: any }) => void) => void }} */ { onSuccess }) => {
            onSuccess((/** @type {{ payload?: any }} */ { payload }) => {
                const effects = payload && payload.effects;
                if (active && effects && effects.webmcpErrors) {
                    captured = effects.webmcpErrors;
                }
            });
        });
    } else if (livewireRef && typeof livewireRef.hook === 'function') {
        off = livewireRef.hook('commit', (/** @type {{ component: any, succeed: (fn: (payload: any) => void) => void }} */ { component: committed, succeed }) => {
            if (committed.id !== component.id) {
                return;
            }
            succeed((/** @type {any} */ payload) => {
                const effects = payload && payload.effects;

                if (active && effects && effects.webmcpErrors) {
                    captured = effects.webmcpErrors;
                }
            });
        });
    }

    return {
        errors: () => captured,
        stop: () => {
            active = false;
            try {
                if (typeof off === 'function') {off();}
            } catch {
                // a failing remover must not turn a finished call into an error
            }
        },
    };
}

/**
 * Runs one action through Livewire. An agent abort stops waiting; the request itself cannot be cancelled in Livewire and may still complete on the server.
 *
 * @param {any} component
 * @param {any} def
 * @param {Record<string, unknown>} args
 * @param {AbortSignal | undefined} signal
 */
async function run(component, def, args, signal) {
    const meta = def.livewire || {};
    const wire = component.$wire;
    const before = errorsSnapshot(component);
    const call = typeof wire.$call === 'function' ? () => wire.$call(meta.method, ...positional(meta, args)) : () => wire[meta.method](...positional(meta, args));

    const capture = captureValidationErrors(component);
    let value;

    try {
        value = await raceAbort(Promise.resolve().then(call), signal);
    } catch (error) {
        capture.stop();
        if ((signal && signal.aborted) || (error && /** @type {any} */ (error).name === 'AbortError')) {
            throw error;
        }
        return { content: [{ type: 'text', text: describe(error) }], isError: true };
    }

    capture.stop();
    const reported = capture.errors();

    if (reported) {
        return { content: [{ type: 'text', text: validationText(reported) }], isError: true };
    }
    const after = errorsSnapshot(component);
    if (after !== before && after !== '{}') {
        return { content: [{ type: 'text', text: validationText(JSON.parse(after)) }], isError: true };
    }
    return toResult(value);
}

/**
 * Drop scopes whose component left the DOM without cleanup having run (defensive, e.g. after wire:navigate).
 */
function prune() {
    for (const [id, entry] of [...scopes]) {
        if (entry.component.el && entry.component.el.isConnected === false) {
            dispose(id);
        }
    }
}

/**
 * Hook into Livewire. Safe to call more than once.
 * @param {any} [Livewire]
 */
function install(Livewire = typeof window !== 'undefined' ? /** @type {any} */ (window).Livewire : undefined) {
    if (installed || !Livewire || typeof Livewire.hook !== 'function') {
        return false;
    }

    installed = true;
    livewireRef = Livewire;
    Livewire.hook('component.init', (/** @type {{ component: any, cleanup: (fn: () => void) => void }} */ { component, cleanup }) => {
        sync(component);
        cleanup(() => dispose(component.id));
    });
    Livewire.hook('commit', (/** @type {{ component: any, succeed: (fn: () => void) => void }} */ { component, succeed }) => {
        succeed(() => {
            queueMicrotask(() => {
                if (!component.el || component.el.isConnected !== false) {
                    sync(component);
                }
            });
        });
    });
    document.addEventListener('livewire:navigated', prune);
    if (typeof Livewire.all === 'function') {
        for (const component of Livewire.all()) {
            sync(component);
        }
    }

    return true;
}

/**
 * Test hook: forget all scopes. Not part of the public API.
 * @private
 */
function resetForTests() {
    for (const id of [...scopes.keys()]) {
        dispose(id);
    }

    installed = false;
    livewireRef = null;
}

if (typeof window !== 'undefined' && !(/** @type {any} */ (globalThis).__WEBMCP_NO_AUTO__)) {
    if (/** @type {any} */ (window).Livewire) {
        install();
    } else {
        document.addEventListener('livewire:init', () => install(), { once: true });
    }
}

export { install, sync, positional, resetForTests };
export default install;
