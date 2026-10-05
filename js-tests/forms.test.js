import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { WebMcp, resetForTests } from '../resources/js/webmcp.js';
import { collect, install, resetForTests as resetForms, run } from '../resources/js/webmcp-forms.js';
import { installDocumentModelContext, MockModelContext, removeDocumentModelContext } from './mock-model-context.js';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const json = (body, status = 200, headers = {}) => new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json', ...headers },
});

const ok = (text) => json({ content: [{ type: 'text', text }] });

/**
 * Builds a form the way <x-webmcp::form> renders it.
 * @param {{ types?: Record<string, string>, confirm?: boolean, token?: string | null, endpoint?: string | null }} [options]
 */
function makeForm({ types = { query: 'string', limit: 'integer', price: 'number', in_stock: 'boolean' }, confirm = false, token = 'form-token', endpoint = '/webmcp/shop/tools/search' } = {}) {
    const form = document.createElement('form');
    form.setAttribute('toolname', 'search');
    form.setAttribute('tooldescription', 'Search the shop');

    if (endpoint) {
        form.setAttribute('data-webmcp-endpoint', endpoint);
    }

    form.setAttribute('data-webmcp-types', JSON.stringify(types));

    if (confirm) {
        form.setAttribute('data-webmcp-confirm', '');
    }

    if (token) {
        form.insertAdjacentHTML('beforeend', `<input type="hidden" name="_token" value="${token}">`);
    }

    for (const [name, type] of Object.entries(types)) {
        const input = document.createElement('input');
        input.name = name;

        if (type === 'boolean') {
            input.type = 'checkbox';
            input.value = '1';
        }

        form.append(input);
    }

    document.body.append(form);

    return form;
}

function fill(form, values) {
    for (const [name, value] of Object.entries(values)) {
        const input = form.elements.namedItem(name);

        if (input.type === 'checkbox') {
            input.checked = Boolean(value);
        } else {
            input.value = value;
        }
    }
}

/** A submit event as a browser with declarative WebMCP dispatches it. */
function submitEvent({ agentInvoked = true, withRespondWith = true } = {}) {
    const event = new Event('submit', { bubbles: true, cancelable: true });
    event.agentInvoked = agentInvoked;
    const calls = [];

    if (withRespondWith) {
        event.respondWith = vi.fn((promise) => calls.push({ promise, defaultPrevented: event.defaultPrevented }));
    }

    return { event, calls };
}

let fetchMock;

beforeEach(() => {
    fetchMock = vi.fn();
    WebMcp.configure({ fetch: fetchMock });
    install();
});

afterEach(() => {
    resetForms();
    resetForTests();
    document.body.replaceChildren();
});

describe('collect', () => {
    it('types the values by the schema types the server rendered', () => {
        const form = makeForm();
        fill(form, { query: 'red shoes', limit: '5', price: '9.5', in_stock: true });

        expect(collect(form)).toEqual({ query: 'red shoes', limit: 5, price: 9.5, in_stock: true });
    });

    it('reads an unchecked checkbox as false', () => {
        const form = makeForm();
        fill(form, { query: 'x' });

        expect(collect(form).in_stock).toBe(false);
    });

    it('omits empty optional fields', () => {
        const form = makeForm();
        fill(form, { query: '', limit: '', price: '' });

        expect(collect(form)).toEqual({ in_stock: false });
    });

    it('sends numbers that do not parse as written so the server can report them', () => {
        const form = makeForm();
        fill(form, { limit: 'many', price: 'cheap' });

        expect(collect(form)).toMatchObject({ limit: 'many', price: 'cheap' });

        fill(form, { limit: '5.5' });

        expect(collect(form).limit).toBe('5.5');
    });

    it('survives broken type metadata and only reads declared fields', () => {
        const form = makeForm();
        form.setAttribute('data-webmcp-types', '{nope');

        expect(collect(form)).toEqual({});

        form.setAttribute('data-webmcp-types', JSON.stringify({ query: 'string' }));
        fill(form, { query: 'a', limit: '3' });

        expect(collect(form)).toEqual({ query: 'a' });
    });
});

describe('agent-invoked submits', () => {
    it('prevents the default navigation BEFORE respondWith and answers with the tool result', async () => {
        fetchMock.mockResolvedValue(ok('found: red shoes'));
        const form = makeForm();
        fill(form, { query: 'red shoes', limit: '5' });

        const { event, calls } = submitEvent();
        form.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(calls).toHaveLength(1);
        expect(calls[0].defaultPrevented).toBe(true); // spec: preventDefault() must be called before respondWith()
        await expect(calls[0].promise).resolves.toEqual({ content: [{ type: 'text', text: 'found: red shoes' }] });
    });

    it('posts the typed arguments to the Session route with the CSRF token from the form', async () => {
        fetchMock.mockResolvedValue(ok('x'));
        const form = makeForm({ token: 'abc123' });
        fill(form, { query: 'hat', limit: '2', in_stock: true });

        const { event, calls } = submitEvent();
        form.dispatchEvent(event);
        await calls[0].promise;

        const [url, init] = fetchMock.mock.calls[0];

        expect(url).toBe('/webmcp/shop/tools/search');
        expect(init.method).toBe('POST');
        expect(init.credentials).toBe('same-origin');
        expect(init.headers['X-CSRF-TOKEN']).toBe('abc123');
        expect(init.headers['X-WebMCP']).toBe('1');
        expect(JSON.parse(init.body)).toEqual({ arguments: { query: 'hat', limit: 2, in_stock: true } });
    });

    it('falls back to the csrf-token meta tag for forms without a _token input (GET forms)', async () => {
        document.head.insertAdjacentHTML('beforeend', '<meta name="csrf-token" content="meta-token">');
        fetchMock.mockResolvedValue(ok('x'));
        const form = makeForm({ token: null });
        fill(form, { query: 'a' });

        const { event, calls } = submitEvent();
        form.dispatchEvent(event);
        await calls[0].promise;

        expect(fetchMock.mock.calls[0][1].headers['X-CSRF-TOKEN']).toBe('meta-token');
        document.head.querySelector('meta[name="csrf-token"]').remove();
    });

    it('maps server failures to readable error results', async () => {
        fetchMock.mockResolvedValueOnce(json({ error: { code: 'forbidden', message: 'You are not allowed to read this resource.' } }, 403));
        const form = makeForm();
        const first = submitEvent();
        form.dispatchEvent(first.event);

        await expect(first.calls[0].promise).resolves.toEqual({ content: [{ type: 'text', text: 'You are not allowed to read this resource.' }], isError: true });

        fetchMock.mockResolvedValueOnce(json(null, 419));
        const second = submitEvent();
        form.dispatchEvent(second.event);

        await expect(second.calls[0].promise).resolves.toMatchObject({ isError: true, content: [{ text: 'Your session expired. Reload the page and try again.' }] });

        fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
        const third = submitEvent();
        form.dispatchEvent(third.event);

        await expect(third.calls[0].promise).resolves.toEqual({ content: [{ type: 'text', text: 'Failed to fetch' }], isError: true });
    });

    it('passes validation errors from the tool through as the result', async () => {
        fetchMock.mockResolvedValue(json({ content: [{ type: 'text', text: 'The query field is required.' }], isError: true }));
        const form = makeForm();

        const { event, calls } = submitEvent();
        form.dispatchEvent(event);

        await expect(calls[0].promise).resolves.toMatchObject({ isError: true });
    });

    it('asks the confirm hook for consequential forms and does not call the server when declined', async () => {
        const confirm = vi.fn().mockResolvedValue(false);
        WebMcp.configure({ confirm });
        const form = makeForm({ confirm: true });
        fill(form, { query: 'x' });

        const { event, calls } = submitEvent();
        form.dispatchEvent(event);

        await expect(calls[0].promise).resolves.toEqual({ content: [{ type: 'text', text: 'The user declined this action.' }], isError: true });
        expect(fetchMock).not.toHaveBeenCalled();
        expect(confirm).toHaveBeenCalledWith(expect.objectContaining({ tool: 'search', description: 'Search the shop', arguments: expect.objectContaining({ query: 'x' }) }));
    });

    it('runs consequential forms once the user confirmed', async () => {
        WebMcp.configure({ confirm: vi.fn().mockResolvedValue(true) });
        fetchMock.mockResolvedValue(ok('done'));
        const form = makeForm({ confirm: true });

        const { event, calls } = submitEvent();
        form.dispatchEvent(event);

        await expect(calls[0].promise).resolves.toEqual({ content: [{ type: 'text', text: 'done' }] });
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('repeats the call with the server confirmation token', async () => {
        WebMcp.configure({ confirm: vi.fn().mockResolvedValue(true) });
        fetchMock
            .mockResolvedValueOnce(json({ content: [{ type: 'text', text: 'needs confirmation' }], isError: true, confirmation: { required: true, token: 'T1' } }))
            .mockResolvedValueOnce(ok('done'));
        const form = makeForm({ confirm: true });

        const { event, calls } = submitEvent();
        form.dispatchEvent(event);

        await expect(calls[0].promise).resolves.toEqual({ content: [{ type: 'text', text: 'done' }] });
        expect(JSON.parse(fetchMock.mock.calls[1][1].body).confirmation).toBe('T1');
    });
});

describe('everything else is left alone', () => {
    it('does not touch submits by the user', () => {
        const form = makeForm();
        const { event, calls } = submitEvent({ agentInvoked: false });

        form.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(calls).toHaveLength(0);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('does not touch submits when agentInvoked is not supported at all', () => {
        const form = makeForm();
        const event = new Event('submit', { bubbles: true, cancelable: true });

        form.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });

    it('does nothing without respondWith (cannot answer the agent)', () => {
        const form = makeForm();
        const { event } = submitEvent({ withRespondWith: false });

        form.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });

    it('ignores forms that are not WebMCP forms of this package', () => {
        const form = makeForm({ endpoint: null });
        const { event, calls } = submitEvent();

        form.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(calls).toHaveLength(0);
    });

    it('installs only once', () => {
        expect(install()).toBe(false);
    });
});

describe('run', () => {
    it('can be called directly', async () => {
        fetchMock.mockResolvedValue(ok('direct'));
        const form = makeForm();
        fill(form, { query: 'q' });

        await expect(run(form)).resolves.toEqual({ content: [{ type: 'text', text: 'direct' }] });
    });
});

describe('declarative forms and imperative registration', () => {
    const payload = (tools) => ({
        version: 1,
        server: 'shop',
        csrf: 'c',
        errors: 'result',
        header: 'X-WebMCP',
        endpoints: { tools: '/webmcp/shop/tools/{name}' },
        tools,
    });

    const tool = (name) => ({ name, title: name, description: `Tool ${name}`, mode: 'session', kind: 'tool', confirm: false });

    it('lets the declarative form win: the same tool is not registered a second time', async () => {
        const mc = installDocumentModelContext(new MockModelContext());
        makeForm();
        const skipped = [];
        WebMcp.on('skipped', (event) => skipped.push(event.detail));

        WebMcp.register(payload([tool('search'), tool('other')]));
        await flush();

        expect(mc.names()).toEqual(['other']);
        expect(skipped).toEqual([{ server: 'shop', tool: 'search', reason: 'declarative-form' }]);

        removeDocumentModelContext();
    });

    it('reports the skip only once per tool, not on every refresh', async () => {
        const mc = installDocumentModelContext(new MockModelContext());
        makeForm();
        const skipped = [];
        WebMcp.on('skipped', (event) => skipped.push(event.detail));

        WebMcp.register(payload([tool('search')]));
        fetchMock.mockResolvedValue(json({ ...payload([tool('search')]), endpoints: { manifest: '/m', tools: '/t/{name}' } }));
        await WebMcp.refresh('shop');
        await flush();

        expect(skipped).toHaveLength(1);
        expect(mc.names()).toEqual([]);

        removeDocumentModelContext();
    });

    it('registers normally when there is no matching form', async () => {
        const mc = installDocumentModelContext(new MockModelContext());

        WebMcp.register(payload([tool('search')]));
        await flush();

        expect(mc.names()).toEqual(['search']);

        removeDocumentModelContext();
    });
});

describe('postTool', () => {
    it('is exposed on the API with the shared error mapping', async () => {
        fetchMock.mockResolvedValue(json(null, 429, { 'retry-after': '5' }));

        await expect(WebMcp.postTool('/t', {})).resolves.toEqual({ content: [{ type: 'text', text: 'Too many requests. Try again in 5 seconds.' }], isError: true });
    });

    it('rethrows aborts instead of turning them into results', async () => {
        const controller = new AbortController();
        fetchMock.mockImplementation((_url, init) => new Promise((_resolve, reject) => {
            init.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
        }));

        const pending = WebMcp.postTool('/t', {}, { signal: controller.signal });
        controller.abort();

        await expect(pending).rejects.toMatchObject({ name: 'AbortError' });
    });

    it('answers unexpected bodies with an error result', async () => {
        fetchMock.mockResolvedValue(json({ nope: true }));

        expect((await WebMcp.postTool('/t', {})).isError).toBe(true);
    });
});

describe('injected forms', () => {
    it('does not send arguments or the CSRF token to a foreign endpoint', async () => {
        const form = makeForm({ endpoint: 'https://evil.example/steal', token: 'page-token' });
        fill(form, { query: 'secret' });

        const { event, calls } = submitEvent();
        form.dispatchEvent(event);

        await expect(calls[0].promise).resolves.toMatchObject({ isError: true, content: [{ text: 'Cross-origin requests are not allowed.' }] });
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
