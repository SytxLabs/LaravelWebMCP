/**
 * Declarative WebMCP forms: answer agent-invoked submits without navigating.
 *
 * The browser turns `<form toolname=...>` into a tool and fills the form for the agent (declarative-api-explainer.md).
 * What happens after the submit is still open in the spec (#135, cross-document response). This script takes the explainer's way for forms that stay on the page:
 * for a submit with `event.agentInvoked` it calls `event.preventDefault()` and then `event.respondWith(promise)`, with the result of the tool call on the
 * Session route. Submits by the user are not touched and behave like any form post to its `action`.
 *
 * Only forms that carry `data-webmcp-endpoint` (rendered by <x-webmcp::form> or @webmcpForm) are handled. Browsers without `SubmitEvent.agentInvoked/respondWith` keep the normal behavior.
 */

import { confirm, postTool } from './webmcp.js';

/**
 * Collects the field values as typed JSON, driven by the schema types the server rendered into the form (`data-webmcp-types`): numbers become numbers, checkboxes become booleans, empty optional fields are omitted.
 * A value that does not parse as the expected number is sent as written so the server reports it.
 *
 * @param {HTMLFormElement} form
 * @returns {Record<string, unknown>}
 */
export function collect(form) {
    /** @type {Record<string, string>} */
    let types = {};

    try {
        types = JSON.parse(form.getAttribute('data-webmcp-types') || '{}');
    } catch {
        types = {};
    }

    const data = new FormData(form);
    /** @type {Record<string, unknown>} */
    const args = {};

    for (const [name, type] of Object.entries(types)) {
        if (type === 'boolean') {
            const control = /** @type {HTMLInputElement | null} */ (form.elements.namedItem(name));
            args[name] = Boolean(control && control.checked);
            continue;
        }

        const raw = data.get(name);
        if (raw === null || raw === '') {
            continue;
        }
        const value = String(raw);
        if (type === 'integer') {
            const parsed = Number.parseInt(value, 10);
            args[name] = Number.isNaN(parsed) || String(parsed) !== value.trim() ? value : parsed;
        } else if (type === 'number') {
            const parsed = Number.parseFloat(value);
            args[name] = Number.isNaN(parsed) ? value : parsed;
        } else {
            args[name] = value;
        }
    }

    return args;
}

/**
 * Runs the form's tool through its Session route and returns the result for `respondWith`.
 *
 * @param {HTMLFormElement} form
 * @returns {Promise<unknown>}
 */
export async function run(form) {
    const url = form.getAttribute('data-webmcp-endpoint') || '';
    const args = collect(form);

    if (form.hasAttribute('data-webmcp-confirm')) {
        const accepted = await confirm({
            tool: form.getAttribute('toolname') || '',
            title: form.getAttribute('toolname') || '',
            description: form.getAttribute('tooldescription') || '',
            arguments: args,
        });

        if (!accepted) {
            return { content: [{ type: 'text', text: 'The user declined this action.' }], isError: true };
        }
    }

    const token = form.querySelector('input[name="_token"]');
    return postTool(url, args, { csrf: token instanceof HTMLInputElement ? token.value : null });
}

/**
 * @param {Event} event
 */
function onSubmit(event) {
    const form = event.target;
    const submit = /** @type {any} */ (event);

    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-webmcp-endpoint')) {
        return;
    }
    if (submit.agentInvoked !== true || typeof submit.respondWith !== 'function') {
        return;
    }
    event.preventDefault(); // must come before respondWith()
    submit.respondWith(run(form));
}

let installed = false;

/**
 * Start listening. Safe to call more than once.
 */
export function install() {
    if (installed || typeof document === 'undefined') {
        return false;
    }
    installed = true;
    document.addEventListener('submit', onSubmit, true);
    return true;
}

/**
 * Test hook. Not part of the public API.
 * @private
 */
export function resetForTests() {
    if (installed) {
        document.removeEventListener('submit', onSubmit, true);
    }

    installed = false;
}

if (typeof document !== 'undefined' && !(/** @type {any} */ (globalThis).__WEBMCP_NO_AUTO__)) {
    install();
}

export default install;
