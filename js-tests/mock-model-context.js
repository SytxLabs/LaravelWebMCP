/**
 * A document.modelContext double that reproduces the WebMCP spec (index.bs at 6891d0e):
 * registerTool() checks in spec order and returns promises, abort() unregisters and rejects the
 * registration promise with the abort reason, executeTool() hands the callback its own AbortSignal,
 * stringifies results and turns rejections into UnknownError. There is deliberately no unregisterTool().
 */
export class MockModelContext extends EventTarget {
    /** @param {{ allowed?: boolean }} [options] allowed=false emulates Permissions-Policy `tools=()` */
    constructor({ allowed = true } = {}) {
        super();
        this.allowed = allowed;
        /** @type {Map<string, any>} */
        this.tools = new Map();
        this.registerCalls = [];
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

        if (tool.inputSchema !== undefined) {
            try {
                JSON.stringify(tool.inputSchema);
            } catch (error) {
                return Promise.reject(error);
            }
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

        this.tools.set(tool.name, { tool, options });

        return new Promise((resolve, reject) => {
            if (options.signal) {
                options.signal.addEventListener('abort', () => {
                    if (this.tools.get(tool.name)?.tool === tool) {
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

    /** Agent side: executeTool(name, input, { signal }) -> Promise<string>. */
    executeTool(name, input = {}, options = {}) {
        const entry = this.tools.get(name);

        if (!entry) {
            return Promise.reject(new DOMException('unknown tool', 'UnknownError'));
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
                    (value) => resolve(JSON.stringify(value)),
                    () => reject(new DOMException('tool failed', 'UnknownError')),
                );
        });
    }

    /** Convenience for tests: execute and parse. */
    async call(name, input = {}, options = {}) {
        return JSON.parse(await this.executeTool(name, input, options));
    }

    names() {
        return [...this.tools.keys()].sort();
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
