import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { WebMcp, resetForTests } from '../resources/js/webmcp.js';
import {
    installDocumentModelContext,
    installNavigatorModelContext,
    MockModelContext,
    removeDocumentModelContext,
    removeNavigatorModelContext,
} from './mock-model-context.js';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const json = (body, status = 200, headers = {}) => new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json', ...headers },
});

const ok = (text) => json({ content: [{ type: 'text', text }] });

const tool = (overrides = {}) => ({
    name: 'weather-tool',
    title: 'Weather',
    description: 'Current weather for a city.',
    inputSchema: { type: 'object', properties: { city: { type: 'string' } }, required: ['city'] },
    annotations: { readOnlyHint: true },
    mode: 'session',
    kind: 'tool',
    confirm: false,
    ...overrides,
});

const payload = (tools = [tool()], extra = {}) => ({
    version: 1,
    server: 'weather',
    csrf: 'csrf-1',
    locale: 'en',
    errors: 'result',
    header: 'X-WebMCP',
    encodeVariables: false,
    confirmation: { serverEnforced: false, header: 'X-WebMCP-Confirmation' },
    endpoints: {
        manifest: '/webmcp/weather/manifest',
        tools: '/webmcp/weather/tools/{name}',
        resources: '/webmcp/weather/resources/{name}',
        bridge: '/mcp/weather',
    },
    tools,
    ...extra,
});

let mc;
let fetchMock;
let removers = [];

/** Collect events of one kind; removed automatically after the test. */
function listen(name) {
    const events = [];
    const listener = (event) => events.push(event.detail);

    WebMcp.on(name, listener);
    removers.push(() => WebMcp.off(name, listener));

    return events;
}

beforeEach(() => {
    mc = installDocumentModelContext(new MockModelContext());
    fetchMock = vi.fn();
    WebMcp.configure({ fetch: fetchMock });
});

afterEach(() => {
    resetForTests();
    removers.forEach((remove) => remove());
    removers = [];
    removeDocumentModelContext();
    removeNavigatorModelContext();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    document.body.innerHTML = '';
});

describe('feature detection', () => {
    it('is a no-op when document.modelContext is missing', () => {
        removeDocumentModelContext();
        const unsupported = listen('unsupported');

        const result = WebMcp.register(payload());

        expect(result).toEqual({ server: 'weather', supported: false, tools: [] });
        expect(unsupported).toHaveLength(1);
        expect(WebMcp.isSupported()).toBe(false);
    });

    it('does not throw when refreshing or unregistering without an API', async () => {
        removeDocumentModelContext();
        WebMcp.register(payload());

        await expect(WebMcp.refresh('weather')).resolves.toBeDefined();
        expect(() => WebMcp.unregister('weather')).not.toThrow();
    });

    it('supports a polyfill through resolveModelContext', () => {
        removeDocumentModelContext();
        const polyfill = new MockModelContext();
        WebMcp.configure({ resolveModelContext: () => polyfill });

        WebMcp.register(payload());

        expect(polyfill.names()).toEqual(['weather-tool']);
    });

    it('falls back to navigator.modelContext (pre-spec) and unregisters through unregisterTool', async () => {
        removeDocumentModelContext();
        const legacy = installNavigatorModelContext(new MockModelContext());
        legacy.unregisterTool = vi.fn();

        WebMcp.register(payload());
        await flush();

        expect(legacy.names()).toEqual(['weather-tool']);

        WebMcp.unregister('weather');

        expect(legacy.unregisterTool).toHaveBeenCalledWith('weather-tool');
        expect(legacy.names()).toEqual([]);
    });

    it('can switch the navigator fallback off', () => {
        removeDocumentModelContext();
        installNavigatorModelContext(new MockModelContext());
        WebMcp.configure({ legacyNavigator: false });

        expect(WebMcp.register(payload()).supported).toBe(false);
    });

    it('prefers document.modelContext over navigator.modelContext', () => {
        const legacy = installNavigatorModelContext(new MockModelContext());

        WebMcp.register(payload());

        expect(mc.names()).toEqual(['weather-tool']);
        expect(legacy.names()).toEqual([]);
    });
});

describe('registration', () => {
    it('registers the spec tool dictionary with a signal and without unknown members', async () => {
        const registered = listen('registered');

        WebMcp.register(payload([tool({ exposedTo: ['https://chat.example.com'] })]));
        await flush();

        const { tool: dictionary, options } = mc.registerCalls[0];

        expect(Object.keys(dictionary).sort()).toEqual(['annotations', 'description', 'execute', 'inputSchema', 'name', 'title']);
        expect(dictionary.annotations).toEqual({ readOnlyHint: true });
        expect(options.signal).toBeInstanceOf(AbortSignal);
        expect(options.exposedTo).toEqual(['https://chat.example.com']);
        expect(registered).toEqual([{ server: 'weather', tool: 'weather-tool' }]);
    });

    it('omits empty title, annotations and exposedTo', async () => {
        WebMcp.register(payload([tool({ title: '', annotations: {}, exposedTo: [] })]));
        await flush();

        const { tool: dictionary, options } = mc.registerCalls[0];

        expect(dictionary).not.toHaveProperty('title');
        expect(dictionary).not.toHaveProperty('annotations');
        expect(dictionary).not.toHaveProperty('outputSchema');
        expect(options).not.toHaveProperty('exposedTo');
    });

    it('registers outputSchema as its own member', async () => {
        const outputSchema = { type: 'object', properties: { temperature: { type: 'number' } }, required: ['temperature'] };

        WebMcp.register(payload([tool({ outputSchema })]));
        await flush();

        const listed = mc.tool('weather-tool');

        expect(mc.registerCalls[0].tool.outputSchema).toEqual(outputSchema);
        expect(mc.registerCalls[0].tool.inputSchema).not.toHaveProperty('outputSchema');
        expect(listed.outputSchema).toEqual(outputSchema);
    });

    it('lists Tool objects whose missing members are null, not undefined', async () => {
        WebMcp.register(payload([tool({ annotations: {}, inputSchema: undefined })]));
        await flush();

        const [listed] = await mc.getTools();

        expect(listed.name).toBe('weather-tool');
        expect(listed.inputSchema).toBeNull();
        expect(listed.outputSchema).toBeNull();
        expect(listed.annotations).toBeNull();
        expect(JSON.stringify(listed)).toBe('{}');
    });

    it('fails a stale Tool with NotFoundError after the name is registered again', async () => {
        WebMcp.register(payload());
        await flush();
        const stale = mc.tool('weather-tool');

        WebMcp.unregister('weather');
        WebMcp.register(payload());
        await flush();

        await expect(mc.executeTool(stale, { city: 'x' })).rejects.toMatchObject({ name: 'NotFoundError' });
        expect(mc.tool('weather-tool')).toBeDefined();
    });

    it('unregisters only through abort() and swallows the expected abort rejection', async () => {
        WebMcp.register(payload([tool(), tool({ name: 'other-tool' })]));
        await flush();

        const signal = mc.registerCalls[0].options.signal;
        const unregistered = listen('unregistered');

        WebMcp.unregister('weather');
        await flush(); // an unhandled rejection here would fail the test run

        expect(signal.aborted).toBe(true);
        expect(mc.names()).toEqual([]);
        expect(unregistered.map((event) => event.tool).sort()).toEqual(['other-tool', 'weather-tool']);
        expect(mc.unregisterTool).toBeUndefined();
    });

    it('reports NotAllowedError (Permissions-Policy tools) without breaking the page', async () => {
        installDocumentModelContext(new MockModelContext({ allowed: false }));
        const errors = listen('error');

        expect(() => WebMcp.register(payload())).not.toThrow();
        await flush();

        expect(errors).toHaveLength(1);
        expect(errors[0]).toMatchObject({ server: 'weather', tool: 'weather-tool', kind: 'registration', name: 'NotAllowedError' });
        expect(WebMcp.tools('weather')).toEqual([]);
    });

    it('reports duplicate tool names (InvalidStateError) and keeps the first registration', async () => {
        const errors = listen('error');

        WebMcp.register(payload());
        WebMcp.register(payload([tool()], { server: 'second' }));
        await flush();

        expect(errors).toHaveLength(1);
        expect(errors[0]).toMatchObject({ server: 'second', name: 'InvalidStateError' });
        expect(mc.names()).toEqual(['weather-tool']);
        expect(WebMcp.tools('weather')).toEqual(['weather-tool']);
    });

    it('reports a SecurityError for exposedTo origins that are not trustworthy', async () => {
        const errors = listen('error');

        WebMcp.register(payload([tool({ exposedTo: ['http://evil.example.com'] })]));
        await flush();

        expect(errors[0]).toMatchObject({ name: 'SecurityError' });
    });

    it('reports a synchronous registerTool throw', async () => {
        mc.registerTool = () => {
            throw new TypeError('boom');
        };
        const errors = listen('error');

        WebMcp.register(payload());
        await flush();

        expect(errors[0]).toMatchObject({ name: 'TypeError', message: 'boom' });
    });

    it('keeps unchanged tools registered when a server is registered again', async () => {
        WebMcp.register(payload());
        await flush();
        let changes = 0;
        mc.addEventListener('toolchange', () => changes++);

        WebMcp.register(payload([tool(), tool({ name: 'other-tool' })]));
        await flush();

        expect(mc.names()).toEqual(['other-tool', 'weather-tool']);
        expect(mc.registerCalls).toHaveLength(2);
        expect(mc.registerCalls[0].options.signal.aborted).toBe(false);
        expect(changes).toBe(1);
    });

    it('replaces changed tools when a server is registered again, without duplicates or leaks', async () => {
        WebMcp.register(payload());
        await flush();
        WebMcp.register(payload([tool({ description: 'Changed.' })]));
        await flush();

        expect(mc.names()).toEqual(['weather-tool']);
        expect(mc.registerCalls).toHaveLength(2);
        expect(mc.registerCalls[0].options.signal.aborted).toBe(true);
        expect(mc.registerCalls[1].options.signal.aborted).toBe(false);
    });

    it('runs kept tools with the new payload (fresh CSRF token)', async () => {
        WebMcp.register(payload());
        await flush();
        WebMcp.register(payload([tool()], { csrf: 'csrf-2' }));
        await flush();
        fetchMock.mockResolvedValue(ok('sunny'));

        await mc.call('weather-tool', { city: 'x' });

        expect(fetchMock.mock.calls[0][1].headers['X-CSRF-TOKEN']).toBe('csrf-2');
    });

    it('reads the embedded manifest by slug and reports invalid ones', async () => {
        document.body.innerHTML = `<script type="application/json" id="webmcp-manifest-weather" data-webmcp-manifest>${JSON.stringify(payload())}</script>`;

        expect(WebMcp.register('weather').tools).toEqual(['weather-tool']);

        const errors = listen('error');

        expect(WebMcp.register('missing')).toBeNull();
        expect(errors[0]).toMatchObject({ kind: 'manifest' });

        document.body.innerHTML = '<script type="application/json" id="webmcp-manifest-broken">{nope</script>';

        expect(WebMcp.register('broken')).toBeNull();
    });

    it('rejects manifests of an unknown version', () => {
        const errors = listen('error');

        expect(WebMcp.register(payload([tool()], { version: 99 }))).toBeNull();
        expect(errors).toHaveLength(1);
    });
});

describe('session execution', () => {
    beforeEach(async () => {
        WebMcp.register(payload());
        await flush();
    });

    it('posts to the tool route with CSRF token and marker header and returns the envelope', async () => {
        fetchMock.mockResolvedValue(ok('sunny in Berlin'));
        const succeeded = listen('succeeded');

        const result = await mc.call('weather-tool', { city: 'Berlin' });

        expect(result).toEqual({ content: [{ type: 'text', text: 'sunny in Berlin' }] });

        const [url, init] = fetchMock.mock.calls[0];

        expect(url).toBe('/webmcp/weather/tools/weather-tool');
        expect(init.method).toBe('POST');
        expect(init.credentials).toBe('same-origin');
        expect(init.headers['X-CSRF-TOKEN']).toBe('csrf-1');
        expect(init.headers['X-WebMCP']).toBe('1');
        expect(init.headers.Accept).toBe('application/json');
        expect(JSON.parse(init.body)).toEqual({ arguments: { city: 'Berlin' } });
        expect(succeeded).toHaveLength(1);
        expect(succeeded[0]).toMatchObject({ server: 'weather', tool: 'weather-tool' });
    });

    it('passes the execute() signal to fetch and ends the request when the agent aborts', async () => {
        let fetchSignal;
        fetchMock.mockImplementation((_url, init) => new Promise((_resolve, reject) => {
            fetchSignal = init.signal;
            init.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
        }));
        const failed = listen('failed');

        const controller = new AbortController();
        const pending = mc.executeTool(mc.tool('weather-tool'), { city: 'x' }, { signal: controller.signal });

        await flush();
        controller.abort();

        await expect(pending).rejects.toMatchObject({ name: 'AbortError' });
        await flush();

        expect(fetchSignal.aborted).toBe(true);
        expect(failed[0]).toMatchObject({ reason: 'aborted' });
    });

    it('resolves server errors as isError results, not rejections', async () => {
        fetchMock.mockResolvedValue(json({ content: [{ type: 'text', text: 'The city field is required.' }], isError: true }));

        const result = await mc.call('weather-tool', {});

        expect(result).toEqual({ content: [{ type: 'text', text: 'The city field is required.' }], isError: true });
    });

    it.each([
        [403, { error: { message: 'You are not allowed to read this resource.' } }, 'You are not allowed to read this resource.'],
        [429, null, 'Too many requests. Try again later.'],
        [500, null, 'The server could not complete the request.'],
        [413, { error: { message: 'The request body is too large.' } }, 'The request body is too large.'],
    ])('maps HTTP %i to a readable isError result', async (status, body, message) => {
        fetchMock.mockResolvedValue(json(body, status));

        const result = await mc.call('weather-tool', { city: 'x' });

        expect(result.isError).toBe(true);
        expect(result.content[0].text).toBe(message);
    });

    it('includes Retry-After on 429', async () => {
        fetchMock.mockResolvedValue(json(null, 429, { 'retry-after': '12' }));

        expect((await mc.call('weather-tool', { city: 'x' })).content[0].text).toBe('Too many requests. Try again in 12 seconds.');
    });

    it('refreshes and drops the tool on 401 and tells the agent', async () => {
        fetchMock
            .mockResolvedValueOnce(json(null, 401))
            .mockResolvedValueOnce(json(null, 401)); // manifest refresh: logged out

        const result = await mc.call('weather-tool', { city: 'x' });
        await flush();

        expect(result).toEqual({ content: [{ type: 'text', text: 'You are not signed in.' }], isError: true });
        expect(fetchMock.mock.calls[1][0]).toBe('/webmcp/weather/manifest');
        expect(mc.names()).toEqual([]);
    });

    it('refreshes the CSRF token on 419 and retries exactly once', async () => {
        fetchMock
            .mockResolvedValueOnce(json(null, 419))
            .mockResolvedValueOnce(json(payload([tool()], { csrf: 'csrf-2' })))
            .mockResolvedValueOnce(ok('retried'));

        const result = await mc.call('weather-tool', { city: 'x' });

        expect(result.content[0].text).toBe('retried');
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(fetchMock.mock.calls[0][1].headers['X-CSRF-TOKEN']).toBe('csrf-1');
        expect(fetchMock.mock.calls[2][1].headers['X-CSRF-TOKEN']).toBe('csrf-2');
    });

    it('gives up after a second 419', async () => {
        fetchMock
            .mockResolvedValueOnce(json(null, 419))
            .mockResolvedValueOnce(json(payload([tool()], { csrf: 'csrf-2' })))
            .mockResolvedValueOnce(json(null, 419))
            .mockResolvedValue(json(payload([tool()], { csrf: 'csrf-3' })));

        const result = await mc.call('weather-tool', { city: 'x' });

        expect(result).toEqual({ content: [{ type: 'text', text: 'Your session expired. Reload the page and try again.' }], isError: true });
        expect(fetchMock.mock.calls.filter(([, init]) => init.method === 'POST')).toHaveLength(2);
    });

    it('treats 404 as a stale tool: refreshes and reports it', async () => {
        fetchMock
            .mockResolvedValueOnce(json({ error: { code: 'tool_not_found' } }, 404))
            .mockResolvedValueOnce(json(payload([])));

        const result = await mc.call('weather-tool', { city: 'x' });
        await flush();

        expect(result.content[0].text).toBe('This tool is no longer available.');
        expect(mc.names()).toEqual([]);
    });

    it('turns network failures into isError results', async () => {
        fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));

        const result = await mc.call('weather-tool', { city: 'x' });

        expect(result).toEqual({ content: [{ type: 'text', text: 'Failed to fetch' }], isError: true });
    });

    it('rejects instead when errors mode is "reject"', async () => {
        WebMcp.configure({ errors: 'reject' });
        fetchMock.mockResolvedValue(json({ content: [{ type: 'text', text: 'nope' }], isError: true }));

        await expect(mc.executeTool(mc.tool('weather-tool'), { city: 'x' })).rejects.toMatchObject({ name: 'OperationError' });
    });

    it('answers unexpected response bodies with an error result', async () => {
        fetchMock.mockResolvedValue(json({ unexpected: true }));

        expect((await mc.call('weather-tool', { city: 'x' })).isError).toBe(true);
    });

    it('uses the confirm hook for consequential tools and does not call the server when declined', async () => {
        resetForTests();
        WebMcp.configure({ fetch: fetchMock, confirm: vi.fn().mockResolvedValue(false) });
        WebMcp.register(payload([tool({ name: 'delete-tool', confirm: true, annotations: { consequentialHint: true } })]));
        await flush();

        const result = await mc.call('delete-tool', { id: 1 });

        expect(result).toEqual({ content: [{ type: 'text', text: 'The user declined this action.' }], isError: true });
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('passes tool details to the confirm hook and runs the tool when accepted', async () => {
        resetForTests();
        const confirm = vi.fn().mockResolvedValue(true);
        WebMcp.configure({ fetch: fetchMock, confirm });
        WebMcp.register(payload([tool({ name: 'delete-tool', title: 'Delete', confirm: true })]));
        await flush();
        fetchMock.mockResolvedValue(ok('deleted'));

        const result = await mc.call('delete-tool', { id: 1 });

        expect(result.content[0].text).toBe('deleted');
        expect(confirm).toHaveBeenCalledWith(expect.objectContaining({ tool: 'delete-tool', title: 'Delete', arguments: { id: 1 } }));
    });

    it('treats a throwing confirm hook as declined', async () => {
        resetForTests();
        WebMcp.configure({ fetch: fetchMock, confirm: () => { throw new Error('ui crashed'); } });
        WebMcp.register(payload([tool({ name: 'delete-tool', confirm: true })]));
        await flush();

        expect((await mc.call('delete-tool')).isError).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('repeats a call with the server confirmation token after the user confirmed', async () => {
        resetForTests();
        const confirm = vi.fn().mockResolvedValue(true);
        WebMcp.configure({ fetch: fetchMock, confirm });
        WebMcp.register(payload([tool({ name: 'delete-tool', confirm: true })]));
        await flush();

        fetchMock
            .mockResolvedValueOnce(json({ content: [{ type: 'text', text: 'needs confirmation' }], isError: true, confirmation: { required: true, token: 'T1' } }))
            .mockResolvedValueOnce(ok('deleted'));

        const result = await mc.call('delete-tool', { id: 1 });

        expect(result.content[0].text).toBe('deleted');
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ arguments: { id: 1 }, confirmation: 'T1' });
    });

    it('routes resource tools to the resources endpoint', async () => {
        resetForTests();
        WebMcp.configure({ fetch: fetchMock });
        WebMcp.register(payload([tool({ name: 'read-settings', kind: 'resource' })]));
        await flush();
        fetchMock.mockResolvedValue(ok('{"theme":"dark"}'));

        await mc.call('read-settings');

        expect(fetchMock.mock.calls[0][0]).toBe('/webmcp/weather/resources/read-settings');
    });

    it('url-encodes tool names in the route', async () => {
        resetForTests();
        WebMcp.configure({ fetch: fetchMock });
        WebMcp.register(payload([tool({ name: 'a.b-c_d' })]));
        await flush();
        fetchMock.mockResolvedValue(ok('x'));

        await mc.call('a.b-c_d');

        expect(fetchMock.mock.calls[0][0]).toBe('/webmcp/weather/tools/a.b-c_d');
    });
});

describe('refresh', () => {
    it('reconciles: removes gone tools, registers new ones, re-registers changed ones', async () => {
        WebMcp.register(payload([tool({ name: 'a' }), tool({ name: 'b' })]));
        await flush();
        const refreshed = listen('refreshed');

        fetchMock.mockResolvedValue(json(payload([tool({ name: 'b', description: 'changed' }), tool({ name: 'c' })])));

        await WebMcp.refresh('weather');
        await flush();

        expect(mc.names()).toEqual(['b', 'c']);
        expect(mc.tools.get('b').tool.description).toBe('changed');
        expect(mc.registerCalls.filter((call) => call.tool.name === 'b')).toHaveLength(2);
        expect(refreshed[0]).toMatchObject({ added: ['b', 'c'].sort(), removed: ['a', 'b'] });
    });

    it('keeps unchanged tools registered (no abort, no re-registration)', async () => {
        WebMcp.register(payload([tool({ name: 'a' })]));
        await flush();
        fetchMock.mockResolvedValue(json(payload([tool({ name: 'a' })])));

        await WebMcp.refresh('weather');

        expect(mc.registerCalls).toHaveLength(1);
        expect(mc.registerCalls[0].options.signal.aborted).toBe(false);
    });

    it('coalesces concurrent refreshes into one request', async () => {
        WebMcp.register(payload());
        await flush();
        fetchMock.mockResolvedValue(json(payload()));

        await Promise.all([WebMcp.refresh('weather'), WebMcp.refresh('weather')]);

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it.each([401, 403, 404])('removes every tool when the manifest endpoint answers %i', async (status) => {
        WebMcp.register(payload([tool({ name: 'a' }), tool({ name: 'b' })]));
        await flush();
        fetchMock.mockResolvedValue(json(null, status));

        await WebMcp.refresh('weather');

        expect(mc.names()).toEqual([]);
        expect(WebMcp.tools('weather')).toEqual([]);
    });

    it('keeps tools on transient failures and reports them', async () => {
        WebMcp.register(payload());
        await flush();
        const errors = listen('error');

        fetchMock.mockRejectedValueOnce(new TypeError('offline'));
        await WebMcp.refresh('weather');

        fetchMock.mockResolvedValueOnce(json(null, 500));
        await WebMcp.refresh('weather');

        expect(mc.names()).toEqual(['weather-tool']);
        expect(errors).toHaveLength(2);
    });

    it('sends the marker header and credentials with the manifest request', async () => {
        WebMcp.register(payload());
        fetchMock.mockResolvedValue(json(payload()));

        await WebMcp.refresh('weather');

        const [url, init] = fetchMock.mock.calls[0];

        expect(url).toBe('/webmcp/weather/manifest');
        expect(init.method).toBe('GET');
        expect(init.credentials).toBe('same-origin');
        expect(init.headers['X-WebMCP']).toBe('1');
    });

    it('refreshes all servers on the auth-changed event (login/logout without reload)', async () => {
        WebMcp.register(payload([tool({ name: 'public' })]));
        await flush();
        fetchMock.mockResolvedValue(json(payload([tool({ name: 'public' }), tool({ name: 'private' })])));

        document.dispatchEvent(new CustomEvent('webmcp:auth-changed'));
        await flush();
        await flush();

        expect(mc.names()).toEqual(['private', 'public']);
    });

    it('refreshes when the tab becomes visible again, at most every refreshMinInterval', async () => {
        WebMcp.configure({ refreshMinInterval: 0 });
        WebMcp.register(payload());
        await flush();
        fetchMock.mockResolvedValue(json(payload()));

        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
        document.dispatchEvent(new Event('visibilitychange'));
        await flush();

        expect(fetchMock).toHaveBeenCalledTimes(1);

        WebMcp.configure({ refreshMinInterval: 60000, refreshOnFocus: true });
        document.dispatchEvent(new Event('visibilitychange'));
        await flush();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});

describe('bridge execution', () => {
    const bridgeTool = (overrides = {}) => tool({
        name: 'renamed',
        mode: 'bridge',
        bridge: { method: 'tools/call', name: 'renamed-tool' },
        ...overrides,
    });

    beforeEach(async () => {
        WebMcp.register(payload([bridgeTool()]));
        await flush();
    });

    it('sends a JSON-RPC tools/call to the MCP endpoint and maps the result', async () => {
        fetchMock.mockResolvedValue(json({ jsonrpc: '2.0', id: 1, result: { content: [{ type: 'text', text: 'renamed' }], isError: false } }));

        const result = await mc.call('renamed', { a: 1 });

        expect(result).toEqual({ content: [{ type: 'text', text: 'renamed' }] });

        const [url, init] = fetchMock.mock.calls[0];
        const body = JSON.parse(init.body);

        expect(url).toBe('/mcp/weather');
        expect(init.headers.Accept).toBe('application/json, text/event-stream');
        expect(init.headers['X-WebMCP']).toBe('1');
        expect(body).toMatchObject({ jsonrpc: '2.0', method: 'tools/call', params: { name: 'renamed-tool', arguments: { a: 1 } } });
        expect(body.params).not.toHaveProperty('_meta');
    });

    it('collects SSE responses of generator tools and drops notifications', async () => {
        const sse = [
            'data: {"jsonrpc":"2.0","method":"notifications/message","params":{"data":"working"}}',
            '',
            'data: {"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"part one"},{"type":"text","text":"part two"}]}}',
            '',
            '',
        ].join('\n');
        fetchMock.mockResolvedValue(new Response(sse, { headers: { 'content-type': 'text/event-stream' } }));

        const result = await mc.call('renamed');

        expect(result.content.map((item) => item.text)).toEqual(['part one', 'part two']);
    });

    it('maps tool errors and non-text content', async () => {
        fetchMock.mockResolvedValue(json({
            jsonrpc: '2.0',
            id: 1,
            result: { isError: true, content: [{ type: 'text', text: 'Cannot do that.' }, { type: 'image', data: 'AAAA', mimeType: 'image/png' }] },
        }));

        const result = await mc.call('renamed');

        expect(result.isError).toBe(true);
        expect(result.content[1].text).toBe('[image content omitted: image/png]');
    });

    it('maps JSON-RPC errors and guard rejections to isError results', async () => {
        fetchMock.mockResolvedValue(json({ jsonrpc: '2.0', id: 1, error: { code: -32003, message: 'This tool is not available to WebMCP clients.' } }, 403));
        const refresh = vi.fn();
        WebMcp.on('refreshed', refresh);

        const result = await mc.call('renamed');
        await flush();

        expect(result).toEqual({ content: [{ type: 'text', text: 'This tool is not available to WebMCP clients.' }], isError: true });
        // a stale manifest is re-fetched
        expect(fetchMock.mock.calls.some(([url]) => url === '/webmcp/weather/manifest')).toBe(true);
        WebMcp.off('refreshed', refresh);
    });

    it('sends the Sanctum XSRF cookie as X-XSRF-TOKEN', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D%3D';
        fetchMock.mockResolvedValue(json({ jsonrpc: '2.0', id: 1, result: { content: [] } }));

        await mc.call('renamed');

        expect(fetchMock.mock.calls[0][1].headers['X-XSRF-TOKEN']).toBe('abc==');
        expect(fetchMock.mock.calls[0][1].credentials).toBe('same-origin');
    });

    it('can authenticate with a bearer token provider instead of cookies', async () => {
        document.cookie = 'XSRF-TOKEN=ignored';
        WebMcp.configure({ bearer: async () => 'tok-123' });
        fetchMock.mockResolvedValue(json({ jsonrpc: '2.0', id: 1, result: { content: [] } }));

        await mc.call('renamed');

        const init = fetchMock.mock.calls[0][1];

        expect(init.headers.Authorization).toBe('Bearer tok-123');
        expect(init.credentials).toBe('omit');
        expect(init.headers).not.toHaveProperty('X-XSRF-TOKEN');
    });

    it('passes the execute() signal to the bridge fetch', async () => {
        let fetchSignal;
        fetchMock.mockImplementation((_url, init) => new Promise((_resolve, reject) => {
            fetchSignal = init.signal;
            init.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
        }));

        const controller = new AbortController();
        const pending = mc.executeTool(mc.tool('renamed'), {}, { signal: controller.signal });
        await flush();
        controller.abort();

        await expect(pending).rejects.toMatchObject({ name: 'AbortError' });
        expect(fetchSignal.aborted).toBe(true);
    });

    it('repeats a call with the confirmation header after HTTP 428', async () => {
        fetchMock
            .mockResolvedValueOnce(json({ jsonrpc: '2.0', id: 1, error: { code: -32004, message: 'Confirmation required.', data: { confirmation: { required: true, token: 'T9' } } } }, 428))
            .mockResolvedValueOnce(json({ jsonrpc: '2.0', id: 2, result: { content: [{ type: 'text', text: 'done' }] } }));

        const result = await mc.call('renamed');

        expect(result.content[0].text).toBe('done');
        expect(fetchMock.mock.calls[1][1].headers['X-WebMCP-Confirmation']).toBe('T9');
    });

    it('refreshes the CSRF token on 419 and retries once', async () => {
        fetchMock
            .mockResolvedValueOnce(json(null, 419))
            .mockResolvedValueOnce(json(payload([bridgeTool()], { csrf: 'csrf-2' })))
            .mockResolvedValueOnce(json({ jsonrpc: '2.0', id: 3, result: { content: [{ type: 'text', text: 'again' }] } }));

        const result = await mc.call('renamed');

        expect(result.content[0].text).toBe('again');
    });

    it('composes resource URIs from the template and maps contents', async () => {
        resetForTests();
        WebMcp.configure({ fetch: fetchMock });
        WebMcp.register(payload([tool({
            name: 'read-doc',
            kind: 'resource',
            mode: 'bridge',
            bridge: { method: 'resources/read', template: 'file://users/{userId}/docs/{docId}' },
        })]));
        await flush();
        fetchMock.mockResolvedValue(json({ jsonrpc: '2.0', id: 1, result: { contents: [{ uri: 'x', text: 'doc 9' }, { uri: 'y', blob: 'AAAA', mimeType: 'image/png' }] } }));

        const result = await mc.call('read-doc', { userId: '3', docId: 9 });

        expect(JSON.parse(fetchMock.mock.calls[0][1].body).params).toEqual({ uri: 'file://users/3/docs/9' });
        expect(result.content).toEqual([
            { type: 'text', text: 'doc 9' },
            { type: 'text', text: '[binary content omitted: image/png]' },
        ]);
    });

    it('rejects missing template variables before any request', async () => {
        resetForTests();
        WebMcp.configure({ fetch: fetchMock });
        WebMcp.register(payload([tool({
            name: 'read-doc',
            kind: 'resource',
            mode: 'bridge',
            bridge: { method: 'resources/read', template: 'file://users/{userId}' },
        })]));
        await flush();

        const result = await mc.call('read-doc', {});

        expect(result.isError).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('supports the generic reader and static resources', async () => {
        resetForTests();
        WebMcp.configure({ fetch: fetchMock });
        WebMcp.register(payload([
            tool({ name: 'read-resource', kind: 'generic-resource', mode: 'bridge', bridge: { method: 'resources/read', generic: true } }),
            tool({ name: 'read-settings', kind: 'resource', mode: 'bridge', bridge: { method: 'resources/read', uri: 'file://resources/settings' } }),
        ]));
        await flush();
        fetchMock.mockResolvedValue(json({ jsonrpc: '2.0', id: 1, result: { contents: [{ text: 'x' }] } }));

        await mc.call('read-resource', { uri: 'file://a/1' });
        await mc.call('read-settings');

        expect(JSON.parse(fetchMock.mock.calls[0][1].body).params).toEqual({ uri: 'file://a/1' });
        expect(JSON.parse(fetchMock.mock.calls[1][1].body).params).toEqual({ uri: 'file://resources/settings' });
    });
});

describe('dialog confirm', () => {
    it('renders the description as text (no HTML) and resolves with the chosen button', async () => {
        HTMLDialogElement.prototype.showModal = function showModal() {
            this.open = true;
        };

        const confirm = WebMcp.createDialogConfirm();
        const pending = confirm({ tool: 't', title: 'Delete <b>all</b>', description: '<img src=x onerror=alert(1)>', arguments: {} });

        const dialog = document.querySelector('dialog[data-webmcp-confirm]');

        expect(dialog.querySelector('img')).toBeNull();
        expect(dialog.textContent).toContain('<img src=x onerror=alert(1)>');

        dialog.returnValue = 'confirm';
        dialog.dispatchEvent(new Event('close'));

        await expect(pending).resolves.toBe(true);
        expect(document.querySelector('dialog')).toBeNull();
    });

    it('resolves false when cancelled', async () => {
        HTMLDialogElement.prototype.showModal = function showModal() {
            this.open = true;
        };

        const pending = WebMcp.createDialogConfirm()({ tool: 't', title: 'T', description: 'd', arguments: {} });
        const dialog = document.querySelector('dialog');
        dialog.returnValue = 'cancel';
        dialog.dispatchEvent(new Event('close'));

        await expect(pending).resolves.toBe(false);
    });

    it('names the dialog, shows the arguments and uses the localized texts', async () => {
        HTMLDialogElement.prototype.showModal = function showModal() {
            this.open = true;
        };
        const messages = { question: 'Darf der Assistent „:title“ ausführen?', allow: 'Erlauben', cancel: 'Abbrechen', arguments: 'Mit diesen Argumenten:' };

        const pending = WebMcp.createDialogConfirm()({ tool: 't', title: 'Löschen', description: 'Löscht die Bestellung.', arguments: { orderId: 42 }, messages });
        const dialog = document.querySelector('dialog');
        const heading = document.getElementById(dialog.getAttribute('aria-labelledby'));
        const body = document.getElementById(dialog.getAttribute('aria-describedby'));

        expect(heading.textContent).toBe('Darf der Assistent „Löschen“ ausführen?');
        expect(body.textContent).toContain('Löscht die Bestellung.');
        expect(dialog.querySelector('pre').textContent).toContain('"orderId": 42');
        expect(dialog.querySelector('pre').style.whiteSpace).toBe('pre-wrap');
        expect(dialog.querySelector('pre').style.overflowWrap).toBe('anywhere');
        expect(dialog.querySelector('pre').style.overflow).toBe('auto');
        expect([...dialog.querySelectorAll('button')].map((button) => button.textContent)).toEqual(['Abbrechen', 'Erlauben']);

        dialog.returnValue = 'cancel';
        dialog.dispatchEvent(new Event('close'));
        await pending;
    });

    it('omits the argument block when there are no arguments', async () => {
        HTMLDialogElement.prototype.showModal = function showModal() {
            this.open = true;
        };

        const pending = WebMcp.createDialogConfirm()({ tool: 't', title: 'T', description: 'd', arguments: {} });
        const dialog = document.querySelector('dialog');

        expect(dialog.querySelector('pre')).toBeNull();
        dialog.returnValue = 'cancel';
        dialog.dispatchEvent(new Event('close'));
        await pending;
    });

    it('shows the arguments in the window.confirm fallback', async () => {
        resetForTests();
        WebMcp.configure({ fetch: fetchMock });
        const spy = vi.spyOn(window, 'confirm').mockReturnValue(false);
        WebMcp.register(payload([tool({ name: 'delete-tool', title: 'Delete', confirm: true })], { messages: { question: 'Run ":title"?' } }));
        await flush();

        await mc.call('delete-tool', { id: 7 });

        expect(spy.mock.calls[0][0]).toContain('Run "Delete"?');
        expect(spy.mock.calls[0][0]).toContain('"id": 7');
        spy.mockRestore();
    });

    it('passes the manifest texts to custom confirm hooks, also for scopes without their own', async () => {
        resetForTests();
        const confirm = vi.fn().mockResolvedValue(false);
        WebMcp.configure({ fetch: fetchMock, confirm });
        const messages = { question: 'Q :title', allow: 'A', cancel: 'C', arguments: 'Args' };
        WebMcp.register(payload([tool({ name: 'delete-tool', confirm: true })], { messages }));
        WebMcp.registerScope('scope-1', [{ name: 'scoped', description: 'd', confirm: true }], { runner: () => 'ran' });
        await flush();

        await mc.call('delete-tool', {});
        await mc.call('scoped', {});

        expect(confirm.mock.calls[0][0].messages).toEqual(messages);
        expect(confirm.mock.calls[1][0].messages).toEqual(messages);
    });
});

describe('same-origin only (injected DOM must not redirect requests or leak the CSRF token)', () => {
    const foreign = (overrides = {}) => payload([tool()], {
        endpoints: {
            manifest: 'https://evil.example/manifest',
            tools: 'https://evil.example/tools/{name}',
            resources: '//evil.example/resources/{name}',
            bridge: 'https://evil.example/mcp',
        },
        ...overrides,
    });

    it('refuses to run a session tool against a foreign origin', async () => {
        WebMcp.register(foreign());
        await flush();

        const result = await mc.call('weather-tool', { city: 'x' });

        expect(result).toEqual({ content: [{ type: 'text', text: 'Cross-origin requests are not allowed.' }], isError: true });
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('refuses protocol-relative and absolute URLs alike', async () => {
        WebMcp.register(payload([tool()], { endpoints: { tools: '//evil.example/t/{name}' } }));
        await flush();

        expect((await mc.call('weather-tool', {})).isError).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('refuses a foreign Bridge endpoint', async () => {
        WebMcp.register(payload([tool({ mode: 'bridge', bridge: { method: 'tools/call', name: 'x' } })], { endpoints: { bridge: 'https://evil.example/mcp' } }));
        await flush();

        const result = await mc.call('weather-tool', {});

        expect(result.isError).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('refuses a foreign manifest endpoint and keeps the registered tools', async () => {
        const errors = [];
        WebMcp.on('error', (event) => errors.push(event.detail));
        WebMcp.register(foreign());
        await flush();

        await WebMcp.refresh('weather');

        expect(fetchMock).not.toHaveBeenCalled();
        expect(errors[0]).toMatchObject({ kind: 'refresh', message: 'Cross-origin requests are not allowed.' });
        expect(mc.names()).toEqual(['weather-tool']);
    });

    it('never sends the page CSRF token to another origin through postTool', async () => {
        document.head.insertAdjacentHTML('beforeend', '<meta name="csrf-token" content="secret-page-token">');

        const result = await WebMcp.postTool('https://evil.example/steal', {});

        expect(result.isError).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
        document.head.querySelector('meta[name="csrf-token"]').remove();
    });

    it('still allows same-origin absolute URLs', async () => {
        fetchMock.mockResolvedValue(ok('fine'));

        const result = await WebMcp.postTool(`${location.origin}/webmcp/x/tools/y`, {});

        expect(result.content[0].text).toBe('fine');
    });
});

describe('one runtime per page', () => {
    it('shares its state when the entry point is loaded twice (Blade adds ?v=..., adapters import it plain)', async () => {
        const copy = await import('../resources/js/webmcp.js?v=123456');

        expect(copy.WebMcp).toBe(WebMcp);
        expect(copy.registerScope).toBe((await import('../resources/js/webmcp.js')).registerScope);

        // a scope registered through the copy is the runtime's scope
        installDocumentModelContext(new MockModelContext());
        copy.registerScope('copy-scope', [{ name: 'copy.tool', description: 'd' }], { runner: () => 'x' });

        expect(WebMcp.servers()).toContain('copy-scope');
    });
});

describe('late ModelContext', () => {
    it('registers everything that was waiting once a polyfill is configured', async () => {
        removeDocumentModelContext();
        const unsupported = listen('unsupported');

        WebMcp.register(payload([tool({ name: 'a' })]));
        WebMcp.registerScope('scope:1', [{ name: 'b', description: 'd' }], { runner: () => 'x' });
        await flush();

        expect(unsupported).toHaveLength(1);
        expect(WebMcp.tools('weather')).toEqual([]);

        const polyfill = new MockModelContext();
        WebMcp.configure({ resolveModelContext: () => polyfill });
        await flush();

        expect(polyfill.names()).toEqual(['a', 'b']);
        expect(WebMcp.tools('weather')).toEqual(['a']);
        expect(WebMcp.tools('scope:1')).toEqual(['b']);
    });

    it('does not register twice when resync runs again', async () => {
        WebMcp.register(payload([tool({ name: 'a' })]));
        await flush();

        WebMcp.resync();
        WebMcp.resync();
        await flush();

        expect(mc.registerCalls).toHaveLength(1);
    });

    it('also picks up navigator.modelContext when the fallback gets switched on later', async () => {
        removeDocumentModelContext();
        WebMcp.configure({ legacyNavigator: false });
        WebMcp.register(payload());
        const legacy = installNavigatorModelContext(new MockModelContext());

        WebMcp.configure({ legacyNavigator: true });
        await flush();

        expect(legacy.names()).toEqual(['weather-tool']);
    });
});

describe('SPA navigation (wire:navigate, Turbo)', () => {
    const embed = (slug, tools) => {
        const script = document.createElement('script');
        script.type = 'application/json';
        script.setAttribute('data-webmcp-manifest', '');
        script.id = `webmcp-manifest-${slug}`;
        script.textContent = JSON.stringify(payload(tools, { server: slug }));
        document.body.append(script);

        return script;
    };

    beforeEach(() => {
        document.body.replaceChildren();
    });

    it('registers manifests of the new page and unregisters those that left', async () => {
        const first = embed('weather', [tool({ name: 'a' })]);
        await WebMcp.autoRegister();
        await flush();
        expect(mc.names()).toEqual(['a']);

        first.remove();
        embed('other', [tool({ name: 'b' })]);
        document.dispatchEvent(new Event('livewire:navigated'));
        await flush();

        expect(mc.names()).toEqual(['b']);
        expect(WebMcp.servers()).toEqual(['other']);
    });

    it('leaves an identical manifest alone (no re-registration)', async () => {
        embed('weather', [tool({ name: 'a' })]);
        await WebMcp.autoRegister();
        await flush();

        document.dispatchEvent(new Event('livewire:navigated'));
        document.dispatchEvent(new Event('turbo:load'));
        await flush();

        expect(mc.registerCalls).toHaveLength(1);
    });

    it('replaces a server whose manifest changed (another user, another page state)', async () => {
        const script = embed('weather', [tool({ name: 'a' })]);
        await WebMcp.autoRegister();
        await flush();

        script.textContent = JSON.stringify(payload([tool({ name: 'a' }), tool({ name: 'c' })], { server: 'weather' }));
        document.dispatchEvent(new Event('livewire:navigated'));
        await flush();

        expect(mc.names()).toEqual(['a', 'c']);
    });

    it('does not touch servers that were registered by hand', async () => {
        WebMcp.register(payload([tool({ name: 'manual' })], { server: 'manual' }));
        await flush();

        document.dispatchEvent(new Event('livewire:navigated'));
        await flush();

        expect(mc.names()).toEqual(['manual']);
    });

    it('survives an invalid embedded manifest', async () => {
        const errors = listen('error');
        const script = embed('weather', [tool()]);
        script.textContent = '{nope';

        document.dispatchEvent(new Event('livewire:navigated'));

        expect(errors[0]).toMatchObject({ kind: 'manifest' });
    });
});

describe('embed (manifest from another origin)', () => {
    const remote = 'https://app.example.com';
    const embedPayload = (tools, extra = {}) => payload(tools, {
        csrf: null,
        endpoints: { manifest: remote + '/webmcp/weather/embed/manifest', bridge: remote + '/' },
        ...extra,
    });
    const bridgeTool = () => tool({ name: 'renamed', mode: 'bridge', bridge: { method: 'tools/call', name: 'renamed-tool' } });

    it('loads the manifest without credentials and runs Bridge calls against that origin only', async () => {
        document.cookie = 'XSRF-TOKEN=page-token';
        fetchMock
            .mockResolvedValueOnce(json(embedPayload([bridgeTool()])))
            .mockResolvedValueOnce(json({ jsonrpc: '2.0', id: 1, result: { content: [{ type: 'text', text: 'renamed' }] } }));

        const registered = await WebMcp.embed(remote + '/webmcp/weather/embed/manifest');
        await flush();

        expect(registered.tools).toEqual(['renamed']);
        expect(fetchMock.mock.calls[0][0]).toBe(remote + '/webmcp/weather/embed/manifest');
        expect(fetchMock.mock.calls[0][1].credentials).toBe('omit');

        const result = await mc.call('renamed', { a: 1 });

        expect(result).toEqual({ content: [{ type: 'text', text: 'renamed' }] });
        const [url, init] = fetchMock.mock.calls[1];
        expect(url).toBe(remote + '/');
        expect(init.credentials).toBe('omit');
        expect(init.headers).not.toHaveProperty('X-XSRF-TOKEN');
        expect(init.headers).not.toHaveProperty('X-CSRF-TOKEN');
        expect(init.headers).not.toHaveProperty('Authorization');
    });

    it('never sends the bearer token of the page to the embedded origin', async () => {
        WebMcp.configure({ bearer: () => 'page-secret' });
        fetchMock
            .mockResolvedValueOnce(json(embedPayload([bridgeTool()])))
            .mockResolvedValueOnce(json({ jsonrpc: '2.0', id: 1, result: { content: [{ type: 'text', text: 'ok' }] } }));
        await WebMcp.embed(remote + '/webmcp/weather/embed/manifest');
        await flush();

        await mc.call('renamed', {});

        expect(fetchMock.mock.calls[1][1].headers).not.toHaveProperty('Authorization');
    });

    it('refuses endpoints on the page origin or a third origin', async () => {
        fetchMock.mockResolvedValueOnce(json(embedPayload([bridgeTool()], { endpoints: { bridge: location.origin + '/mcp/weather' } })));
        await WebMcp.embed(remote + '/webmcp/weather/embed/manifest');
        await flush();

        expect((await mc.call('renamed', {})).isError).toBe(true);

        fetchMock.mockResolvedValueOnce(json(embedPayload([bridgeTool()], { endpoints: { bridge: 'https://evil.example/mcp' } })));
        await WebMcp.embed(remote + '/webmcp/weather/embed/manifest');
        await flush();

        expect((await mc.call('renamed', {})).isError).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('does not run session tools of an embedded server', async () => {
        fetchMock.mockResolvedValueOnce(json(embedPayload([tool()], { endpoints: { tools: remote + '/webmcp/weather/tools/{name}' } })));
        await WebMcp.embed(remote + '/webmcp/weather/embed/manifest');
        await flush();

        expect((await mc.call('weather-tool', { city: 'x' })).isError).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('mirrors x-mcp-header arguments into Mcp-Param headers like laravel/mcp expects', async () => {
        const headers = [
            { path: ['region'], header: 'Mcp-Param-Region', type: 'string' },
            { path: ['limit'], header: 'Mcp-Param-Limit', type: 'integer' },
            { path: ['filter', 'open'], header: 'Mcp-Param-Open', type: 'boolean' },
            { path: ['city'], header: 'Mcp-Param-City', type: 'string' },
            { path: ['missing'], header: 'Mcp-Param-Missing', type: 'string' },
        ];
        const mirrored = tool({ name: 'orders', mode: 'bridge', bridge: { method: 'tools/call', name: 'orders', headers } });
        fetchMock.mockResolvedValue(json({ jsonrpc: '2.0', id: 1, result: { content: [{ type: 'text', text: 'ok' }] } }));
        await WebMcp.embed(remote + '/webmcp/weather/embed/manifest', embedPayload([mirrored]));
        await flush();

        await mc.call('orders', { region: 'eu', limit: 5, filter: { open: true }, city: 'Köln' });

        const sent = fetchMock.mock.calls[0][1].headers;
        expect(sent['Mcp-Param-Region']).toBe('eu');
        expect(sent['Mcp-Param-Limit']).toBe('5');
        expect(sent['Mcp-Param-Open']).toBe('true');
        expect(sent['Mcp-Param-City']).toBe('=?base64?S8O2bG4=?=');
        expect(sent).not.toHaveProperty('Mcp-Param-Missing');
    });

    it('registers an inlined manifest without fetching it, and still refuses foreign endpoints', async () => {
        const registered = await WebMcp.embed(remote + '/webmcp/weather/embed/manifest', embedPayload([bridgeTool()]));
        await flush();

        expect(registered.tools).toEqual(['renamed']);
        expect(fetchMock).not.toHaveBeenCalled();

        WebMcp.unregister('weather');
        await WebMcp.embed(remote + '/webmcp/weather/embed/manifest', embedPayload([bridgeTool()], { endpoints: { bridge: 'https://evil.example/mcp' } }));
        await flush();

        expect((await mc.call('renamed', {})).isError).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('refreshes from the embedded origin without credentials', async () => {
        fetchMock
            .mockResolvedValueOnce(json(embedPayload([bridgeTool()])))
            .mockResolvedValueOnce(json(embedPayload([])));
        await WebMcp.embed(remote + '/webmcp/weather/embed/manifest');
        await flush();

        const result = await WebMcp.refresh('weather');

        expect(result.removed).toEqual(['renamed']);
        expect(fetchMock.mock.calls[1][0]).toBe(remote + '/webmcp/weather/embed/manifest');
        expect(fetchMock.mock.calls[1][1].credentials).toBe('omit');
    });

    it('reports invalid URLs, failed and broken manifests as error events', async () => {
        const errors = listen('error');

        expect(await WebMcp.embed('not a url')).toBeNull();
        fetchMock.mockResolvedValueOnce(json({}, 403));
        expect(await WebMcp.embed(remote + '/m')).toBeNull();
        fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
        expect(await WebMcp.embed(remote + '/m')).toBeNull();
        fetchMock.mockResolvedValueOnce(json({ version: 99 }));
        expect(await WebMcp.embed(remote + '/m')).toBeNull();

        expect(errors.map((error) => error.kind)).toEqual(['embed', 'embed', 'embed', 'manifest']);
    });
});

describe('structuredContent of Bridge results', () => {
    const bridgeTool = () => tool({ name: 'renamed', mode: 'bridge', bridge: { method: 'tools/call', name: 'renamed-tool' } });
    const answer = () => json({ jsonrpc: '2.0', id: 1, result: { content: [{ type: 'text', text: '{"a":1}' }], structuredContent: { a: 1 } } });

    it('drops it by default, like Session mode', async () => {
        WebMcp.register(payload([bridgeTool()]));
        await flush();
        fetchMock.mockResolvedValueOnce(answer());

        expect(await mc.call('renamed', {})).toEqual({ content: [{ type: 'text', text: '{"a":1}' }] });
    });

    it('keeps it when the manifest says so (features.structured_content)', async () => {
        WebMcp.register(payload([bridgeTool()], { structuredContent: true }));
        await flush();
        fetchMock.mockResolvedValueOnce(answer());

        expect(await mc.call('renamed', {})).toEqual({ content: [{ type: 'text', text: '{"a":1}' }], structuredContent: { a: 1 } });
    });
});
