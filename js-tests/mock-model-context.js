/**
 * A document.modelContext double that reproduces the WebMCP spec (index.bs at 14b6d53):
 * registerTool() checks in spec order and returns promises, abort() unregisters and rejects the
 * registration promise with the abort reason. getTools() returns Tool objects bound to one registration,
 * so executeTool() runs exactly that registration and a stale Tool fails with NotFoundError even after
 * the name is registered again. A rejected execute() becomes OperationError. There is no unregisterTool().
 */

const ids = new WeakMap();

/** Read-only view of one registration, like the spec's Tool interface: no own enumerable properties, not cloneable. */
export class MockTool {
    #entry;

    constructor(entry) {
        this.#entry = entry;
        ids.set(this, entry.id);
    }

    get name() { return this.#entry.tool.name; }

    get title() { return this.#entry.tool.title ?? ''; }

    get description() { return this.#entry.tool.description; }

    get inputSchema() { return this.#entry.inputSchema; }

    get outputSchema() { return this.#entry.outputSchema; }

    get annotations() { return this.#entry.tool.annotations ? { ...this.#entry.tool.annotations } : null; }

    get origin() { return globalThis.location?.origin ?? 'null'; }

    get source() { return globalThis.window ?? null; }
}

/** The spec serializes schemas on registration and hands parsed copies out again. */
function serializeSchema(schema) {
    if (schema === undefined) {
        return { value: null };
    }
    if (schema === null || typeof schema !== 'object') {
        throw new TypeError('schema must be an object');
    }

    return { value: JSON.parse(JSON.stringify(schema)) };
}

export class MockModelContext extends EventTarget {
    /** @param {{ allowed?: boolean }} [options] allowed=false emulates Permissions-Policy `tools=()` */
    constructor({ allowed = true } = {}) {
        super();
        this.allowed = allowed;
        /** @type {Map<string, any>} */
        this.tools = new Map();
        this.registerCalls = [];
        this.nextId = 0;
    }

    registerTool(tool, options = {}) {
        this.registerCalls.push({ tool, options });

        if (!this.allowed) {
            return Promise.reject(new DOMException('tools is not allowed', 'NotAllowedError'));
        }

        if (this.tools.has(tool.name)) {
            return Promise.reject(new DOMException('duplicate tool name', 'InvalidStateError'));
        }

        if (!/^[A-Za-z0-9_.-]{1,128}$/.test(tool.name)) {
            return Promise.reject(new DOMException('invalid tool name', 'InvalidStateError'));
        }

        if (!tool.description) {
            return Promise.reject(new DOMException('empty description', 'InvalidStateError'));
        }

        let inputSchema;
        let outputSchema;

        try {
            inputSchema = serializeSchema(tool.inputSchema).value;
            outputSchema = serializeSchema(tool.outputSchema).value;
        } catch (error) {
            return Promise.reject(error);
        }

        if (options.signal && options.signal.aborted) {
            return Promise.reject(options.signal.reason);
        }

        for (const origin of options.exposedTo || []) {
            let url;

            try {
                url = new URL(origin);
            } catch {
                return Promise.reject(new DOMException('invalid origin', 'SecurityError'));
            }

            const local = url.hostname === 'localhost' || url.hostname === '127.0.0.1';

            if (url.protocol !== 'https:' && !(url.protocol === 'http:' && local)) {
                return Promise.reject(new DOMException('origin is not potentially trustworthy', 'SecurityError'));
            }
        }

        const entry = { id: ++this.nextId, tool, options, inputSchema, outputSchema };
        this.tools.set(tool.name, entry);

        return new Promise((resolve, reject) => {
            if (options.signal) {
                options.signal.addEventListener('abort', () => {
                    if (this.tools.get(tool.name) === entry) {
                        this.tools.delete(tool.name);
                        this.dispatchEvent(new Event('toolchange'));
                    }

                    reject(options.signal.reason);
                }, { once: true });
            }

            queueMicrotask(() => {
                this.dispatchEvent(new Event('toolchange'));
                resolve(undefined);
            });
        });
    }

    /** Agent side: getTools() -> Promise<Tool[]>. */
    getTools() {
        return Promise.resolve(this.#snapshot());
    }

    /** Agent side: executeTool(tool, input, { signal }) -> Promise<string>. */
    executeTool(tool, input = {}, options = {}) {
        if (!ids.has(tool)) {
            return Promise.reject(new TypeError('executeTool() expects a Tool from getTools()'));
        }

        const entry = [...this.tools.values()].find((candidate) => candidate.id === ids.get(tool));

        if (!entry) {
            return Promise.reject(new DOMException('tool is no longer registered', 'NotFoundError'));
        }

        if (options.signal && options.signal.aborted) {
            return Promise.reject(options.signal.reason);
        }

        const controller = new AbortController();

        return new Promise((resolve, reject) => {
            if (options.signal) {
                options.signal.addEventListener('abort', () => {
                    controller.abort(options.signal.reason);
                    reject(options.signal.reason);
                }, { once: true });
            }

            Promise.resolve()
                .then(() => entry.tool.execute(input, { signal: controller.signal }))
                .then(
                    (value) => {
                        const text = JSON.stringify(value);

                        if (text === undefined) {
                            reject(new DOMException('unserializable result', 'OperationError'));
                        } else {
                            resolve(text);
                        }
                    },
                    () => reject(new DOMException('tool rejected', 'OperationError')),
                );
        });
    }

    /** Test shortcut: the current Tool for a name, what getTools() would return for it. */
    tool(name) {
        return this.#snapshot().find((tool) => tool.name === name);
    }

    /** Test shortcut: execute by name and parse. */
    async call(name, input = {}, options = {}) {
        return JSON.parse(await this.executeTool(this.tool(name), input, options));
    }

    names() {
        return [...this.tools.keys()].sort();
    }

    #snapshot() {
        return [...this.tools.values()].map((entry) => new MockTool(entry));
    }
}

/** Installs a mock as document.modelContext (so `"modelContext" in document` is true). */
export function installDocumentModelContext(mock = new MockModelContext()) {
    Object.defineProperty(document, 'modelContext', { configurable: true, writable: true, value: mock });

    return mock;
}

export function removeDocumentModelContext() {
    delete document.modelContext;
}

export function installNavigatorModelContext(mock = new MockModelContext()) {
    Object.defineProperty(navigator, 'modelContext', { configurable: true, writable: true, value: mock });

    return mock;
}

export function removeNavigatorModelContext() {
    delete navigator.modelContext;
}
