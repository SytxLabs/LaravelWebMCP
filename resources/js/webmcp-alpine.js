/**
 * Alpine plugin: `x-webmcp` binds a WebMCP tool to the lifecycle of an element.
 *
 *     <div x-data="{ count: 0 }">
 *         <span x-webmcp="{
 *             name: 'counter.increment',
 *             description: 'Increments the counter on this page',
 *             inputSchema: { type: 'object', properties: { by: { type: 'integer' } } },
 *             execute: ({ by = 1 }) => { count += by; return `count is ${count}` },
 *         }"></span>
 *     </div>
 *
 * The tool is registered when the element initializes, replaced when the expression's definition changes, and aborted (unregistered) when the element is removed.
 * `execute` receives `(args, { signal })` and may return a string, an object or `{ content: [...] }`.
 *
 * Tool names must be unique per page. For tools that call your backend, prefer @webmcp / #[WebMcp].
 */

import { registerScope } from './webmcp.js';

let counter = 0;

/**
 * @param {any} spec
 * @returns {any}
 */
function toDefinition(spec) {
    if (!spec || typeof spec !== 'object') {
        throw new Error('x-webmcp expects an object.');
    }

    if (typeof spec.name !== 'string' || spec.name === '') {
        throw new Error('x-webmcp needs a "name".');
    }

    if (typeof spec.description !== 'string' || spec.description === '') {
        throw new Error('x-webmcp needs a "description".');
    }

    if (typeof spec.execute !== 'function') {
        throw new Error('x-webmcp needs an "execute" function.');
    }

    const annotations = spec.annotations && typeof spec.annotations === 'object' ? { ...spec.annotations } : {};

    /** @type {any} */
    const def = {name: spec.name, description: spec.description, kind: 'alpine', confirm: Boolean(spec.confirm ?? annotations.consequentialHint), annotations};
    if (spec.title) {
        def.title = String(spec.title);
    }
    const schema = spec.inputSchema ?? spec.schema;
    if (schema) {
        def.inputSchema = schema;
    }
    if (Array.isArray(spec.exposedTo)) {
        def.exposedTo = [...spec.exposedTo];
    }
    return def;
}

/**
 * @param {any} Alpine
 */
export default function webmcpAlpine(Alpine) {
    Alpine.directive('webmcp', (/** @type {Element} */ el, /** @type {any} */ { expression }, /** @type {any} */ { evaluate, effect, cleanup }) => {
        const id = 'alpine:' + (++counter);
        /** @type {any} */
        let latest = null;
        /** @type {ReturnType<typeof registerScope> | null} */
        let scope = null;

        effect(() => {
            let def;
            let spec;
            try {
                spec = evaluate(expression);
                def = toDefinition(spec);
            } catch (error) {
                console.error('[webmcp] x-webmcp:', error instanceof Error ? error.message : error, el);
                return;
            }

            // Keep calling the newest execute() even though the registered definition did not change.
            latest = spec;
            if (scope) {
                scope.sync([def]);
            } else {
                scope = registerScope(id, [def], {runner: (_def, /** @type {Record<string, unknown>} */ args, /** @type {AbortSignal | undefined} */ signal) => latest.execute(args, { signal })});
            }
        });

        cleanup(() => {
            if (scope) {
                scope.dispose();
                scope = null;
            }
        });
    });
}

if (typeof document !== 'undefined' && !(/** @type {any} */ (globalThis).__WEBMCP_NO_AUTO__)) {
    document.addEventListener('alpine:init', () => {
        const alpine = /** @type {any} */ (window).Alpine;
        if (alpine) {
            alpine.plugin(webmcpAlpine);
        }
    }, { once: true });
}
