import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { WebMcp, resetForTests } from '../resources/js/webmcp.js';
import { install, positional, resetForTests as resetLivewire } from '../resources/js/webmcp-livewire.js';
import { installDocumentModelContext, MockModelContext, removeDocumentModelContext } from './mock-model-context.js';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const action = (overrides = {}) => ({
    name: 'cart.add-to-cart',
    title: 'Add To Cart',
    description: 'Adds a product to the cart',
    inputSchema: { type: 'object', properties: { productId: { type: 'integer' }, qty: { type: 'integer', default: 1 } }, required: ['productId'] },
    kind: 'livewire',
    confirm: false,
    livewire: { method: 'addToCart', params: ['productId', 'qty'], defaults: { qty: 1 }, variadic: null },
    ...overrides,
});

/** A Livewire double with the hook surface the adapter uses: hook(), all(), component.init / commit. */
function fakeLivewire() {
    const hooks = {};
    const components = new Map();

    return {
        components,
        hook(name, callback) {
            (hooks[name] ||= []).push(callback);

            // like Livewire.hook(): returns the function that removes the listener
            return () => {
                hooks[name] = hooks[name].filter((registered) => registered !== callback);
            };
        },
        all: () => [...components.values()],
        trigger(name, payload) {
            (hooks[name] || []).forEach((callback) => callback(payload));
        },
        hooks,
    };
}

let livewire;
let mc;
let cleanups;

function makeComponent(id, tools, { call } = {}) {
    const el = document.createElement('div');
    el.setAttribute('wire:id', id);
    el.append(manifestScript(id, tools));
    document.body.append(el);

    return {
        id,
        el,
        snapshot: { memo: { errors: {} } },
        $wire: { $call: call || vi.fn().mockResolvedValue(undefined) },
    };
}

function manifestScript(id, tools) {
    const script = document.createElement('script');
    script.type = 'application/json';
    script.setAttribute('data-webmcp-livewire', '');
    script.setAttribute('data-webmcp-component', id);
    script.textContent = JSON.stringify({ version: 1, component: id, tools });

    return script;
}

/** Livewire initializes the component: component.init hook with a cleanup registrar. */
function init(component) {
    livewire.components.set(component.id, component);
    const own = [];
    cleanups.set(component.id, own);
    livewire.trigger('component.init', { component, cleanup: (fn) => own.push(fn) });
}

function destroy(component) {
    (cleanups.get(component.id) || []).forEach((fn) => fn());
    livewire.components.delete(component.id);
    component.el.remove();
}

async function commit(component) {
    livewire.trigger('commit', { component, succeed: (fn) => fn({ snapshot: {}, effects: {} }) });
    await flush();
}

beforeEach(() => {
    mc = installDocumentModelContext(new MockModelContext());
    livewire = fakeLivewire();
    cleanups = new Map();
    install(livewire);
});

afterEach(() => {
    resetLivewire();
    resetForTests();
    removeDocumentModelContext();
    document.body.innerHTML = '';
});

describe('lifecycle', () => {
    it('registers a component\'s actions when it initializes', async () => {
        init(makeComponent('c1', [action()]));
        await flush();

        expect(mc.names()).toEqual(['cart.add-to-cart']);
        expect(WebMcp.servers()).toContain('livewire:c1');
        expect(mc.registerCalls[0].tool.title).toBe('Add To Cart');
        expect(mc.registerCalls[0].tool.inputSchema.required).toEqual(['productId']);
    });

    it('does nothing for components that publish no actions or a broken manifest', async () => {
        const plain = document.createElement('div');
        document.body.append(plain);
        init({ id: 'plain', el: plain, snapshot: { memo: { errors: {} } }, $wire: {} });

        const broken = makeComponent('broken', []);
        broken.el.querySelector('script').textContent = '{nope';
        init(broken);

        const empty = makeComponent('empty', []);
        init(empty);
        await flush();

        expect(mc.names()).toEqual([]);
    });

    it('aborts the tools when the component is destroyed (removal, wire:navigate)', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();

        const signal = mc.registerCalls[0].options.signal;
        destroy(component);
        await flush();

        expect(signal.aborted).toBe(true);
        expect(mc.names()).toEqual([]);
        expect(WebMcp.servers()).not.toContain('livewire:c1');
    });

    it('keeps independent scopes per component instance (no duplicates, no leaks)', async () => {
        const a = makeComponent('a', [action({ name: 'row.archive.a' })]);
        const b = makeComponent('b', [action({ name: 'row.archive.b' })]);
        init(a);
        init(b);
        await flush();

        expect(mc.names()).toEqual(['row.archive.a', 'row.archive.b']);

        destroy(a);
        await flush();

        expect(mc.names()).toEqual(['row.archive.b']);
    });

    it('reports duplicate names across instances without breaking the page', async () => {
        const errors = [];
        WebMcp.on('error', (event) => errors.push(event.detail));

        init(makeComponent('a', [action()]));
        init(makeComponent('b', [action()]));
        await flush();

        expect(mc.names()).toEqual(['cart.add-to-cart']);
        expect(errors[0]).toMatchObject({ name: 'InvalidStateError', server: 'livewire:b' });
    });

    it('re-reads the published actions after every update (commit)', async () => {
        const component = makeComponent('c1', [action({ name: 'a' }), action({ name: 'b' })]);
        init(component);
        await flush();

        component.el.querySelector('script').replaceWith(manifestScript('c1', [action({ name: 'b' }), action({ name: 'c' })]));
        await commit(component);

        expect(mc.names()).toEqual(['b', 'c']);
    });

    it('does not re-register unchanged actions on update', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();

        await commit(component);
        await commit(component);

        expect(mc.registerCalls).toHaveLength(1);
    });

    it('starts registering when a later update publishes actions, and stops when they disappear', async () => {
        const component = makeComponent('c1', []);
        init(component);
        await flush();
        expect(mc.names()).toEqual([]);

        component.el.querySelector('script').replaceWith(manifestScript('c1', [action()]));
        await commit(component);
        expect(mc.names()).toEqual(['cart.add-to-cart']);

        component.el.querySelector('script').remove();
        await commit(component);
        expect(mc.names()).toEqual([]);
    });

    it('ignores updates of components that already left the DOM', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();

        component.el.remove();
        await commit(component);

        expect(mc.names()).toEqual(['cart.add-to-cart']); // cleanup, not commit, is responsible for removal
    });

    it('prunes scopes of detached components on livewire:navigated', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();

        component.el.remove(); // cleanup never ran
        document.dispatchEvent(new Event('livewire:navigated'));
        await flush();

        expect(mc.names()).toEqual([]);
    });

    it('picks up components that initialized before the adapter was installed', async () => {
        resetLivewire();
        resetForTests();

        const late = fakeLivewire();
        late.components.set('early', makeComponent('early', [action()]));

        install(late);
        await flush();

        expect(mc.names()).toEqual(['cart.add-to-cart']);
    });

    it('installs only once', () => {
        expect(install(livewire)).toBe(false);
    });

    it('does not install without a usable Livewire', () => {
        resetLivewire();

        expect(install(undefined)).toBe(false);
        expect(install({})).toBe(false);
    });
});

describe('execution through $wire.$call', () => {
    it('calls the Livewire method positionally in declared order', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();

        component.$wire.$call.mockResolvedValue('added 3 of 5');

        const result = await mc.call('cart.add-to-cart', { productId: 5, qty: 3 });

        expect(component.$wire.$call).toHaveBeenCalledWith('addToCart', 5, 3);
        expect(result).toEqual({ content: [{ type: 'text', text: 'added 3 of 5' }] });
    });

    it('omits trailing optional arguments so PHP defaults apply', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();

        await mc.call('cart.add-to-cart', { productId: 5 });

        expect(component.$wire.$call).toHaveBeenCalledWith('addToCart', 5);
    });

    it('fills gaps with defaults so later arguments land in the right slot', () => {
        const meta = { params: ['a', 'b', 'c'], defaults: { a: 1, b: 2, c: 3 } };

        expect(positional(meta, { c: 9 })).toEqual([1, 2, 9]);
        expect(positional(meta, {})).toEqual([]);
        expect(positional({ params: ['a', 'b'], defaults: {} }, { b: 7 })).toEqual([null, 7]);
    });

    it('spreads a variadic parameter', () => {
        const meta = { params: ['label', 'rest'], defaults: {}, variadic: 'rest' };

        expect(positional(meta, { label: 'x', rest: [1, 2, 3] })).toEqual(['x', 1, 2, 3]);
    });

    it('maps return values to results', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();
        const call = component.$wire.$call;

        call.mockResolvedValueOnce(undefined);
        expect((await mc.call('cart.add-to-cart', { productId: 1 })).content[0].text).toBe('Done.');

        call.mockResolvedValueOnce({ total: 12, items: ['a'] });
        expect((await mc.call('cart.add-to-cart', { productId: 1 })).content[0].text).toBe('{"total":12,"items":["a"]}');

        call.mockResolvedValueOnce(42);
        expect((await mc.call('cart.add-to-cart', { productId: 1 })).content[0].text).toBe('42');
    });

    it('reports Livewire 4 validation failures (rejection with errors) as readable error results', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();

        component.$wire.$call.mockRejectedValue({ status: 422, errors: { qty: ['The qty must be at least 1.'], productId: ['The product id is required.'] } });

        const result = await mc.call('cart.add-to-cart', { productId: 1, qty: 0 });

        expect(result).toEqual({ content: [{ type: 'text', text: 'The qty must be at least 1. The product id is required.' }], isError: true });
    });

    it.each([
        [403, 'You are not allowed to do this.'],
        [419, 'Your session expired. Reload the page and try again.'],
        [404, 'This action is no longer available.'],
        [429, 'Too many requests. Try again later.'],
        [500, 'The action failed.'],
    ])('maps a %i rejection to a readable message', async (status, message) => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();
        component.$wire.$call.mockRejectedValue({ status });

        expect((await mc.call('cart.add-to-cart', { productId: 1 })).content[0].text).toBe(message);
    });

    it('reports validation failures on Livewire 3 through the changed error bag', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();

        component.$wire.$call.mockImplementation(async () => {
            component.snapshot = { memo: { errors: { qty: ['The qty must be at least 1.'] } } };
        });

        const result = await mc.call('cart.add-to-cart', { productId: 1, qty: 0 });

        expect(result).toEqual({ content: [{ type: 'text', text: 'The qty must be at least 1.' }], isError: true });
    });

    it('does not blame a successful call for an error bag it did not change', async () => {
        const component = makeComponent('c1', [action()]);
        component.snapshot = { memo: { errors: { other: ['Left over from an earlier validation.'] } } };
        init(component);
        await flush();
        component.$wire.$call.mockResolvedValue('ok');

        expect(await mc.call('cart.add-to-cart', { productId: 1 })).toEqual({ content: [{ type: 'text', text: 'ok' }] });
    });

    it('falls back to $wire[method]() when $call is missing', async () => {
        const component = makeComponent('c1', [action()]);
        component.$wire = { addToCart: vi.fn().mockResolvedValue('direct') };
        init(component);
        await flush();

        const result = await mc.call('cart.add-to-cart', { productId: 2 });

        expect(component.$wire.addToCart).toHaveBeenCalledWith(2);
        expect(result.content[0].text).toBe('direct');
    });

    it('stops waiting when the agent aborts', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();

        component.$wire.$call.mockReturnValue(new Promise(() => {})); // never settles
        const failed = [];
        WebMcp.on('failed', (event) => failed.push(event.detail));

        const controller = new AbortController();
        const pending = mc.executeTool('cart.add-to-cart', { productId: 1 }, { signal: controller.signal });
        await flush();
        controller.abort();

        await expect(pending).rejects.toMatchObject({ name: 'AbortError' });
        await flush();

        expect(failed[0]).toMatchObject({ reason: 'aborted' });
    });

    it('uses the confirm hook for consequential actions', async () => {
        const confirm = vi.fn().mockResolvedValue(false);
        WebMcp.configure({ confirm });
        const component = makeComponent('c1', [action({ name: 'cart.clear', confirm: true, annotations: { consequentialHint: true }, livewire: { method: 'clear', params: [], defaults: {}, variadic: null } })]);
        init(component);
        await flush();

        const result = await mc.call('cart.clear');

        expect(result).toEqual({ content: [{ type: 'text', text: 'The user declined this action.' }], isError: true });
        expect(confirm).toHaveBeenCalledWith(expect.objectContaining({ tool: 'cart.clear' }));
        expect(component.$wire.$call).not.toHaveBeenCalled();
    });

    it('rejects instead when errors mode is "reject"', async () => {
        WebMcp.configure({ errors: 'reject' });
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();
        component.$wire.$call.mockRejectedValue({ status: 403 });

        await expect(mc.executeTool('cart.add-to-cart', { productId: 1 })).rejects.toMatchObject({ name: 'UnknownError' });
    });

    it('survives a script id containing quotes', async () => {
        const component = makeComponent('we"ird\\id', [action()]);
        init(component);
        await flush();

        expect(mc.names()).toEqual(['cart.add-to-cart']);
    });
});

describe('validation errors swallowed by Livewire (webmcpErrors effect)', () => {
    const effectsFor = (component, errors) => livewire.trigger('commit', {
        component,
        succeed: (fn) => fn({ snapshot: {}, effects: { webmcpErrors: errors } }),
    });

    it('turns the effect of the response into an error result', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();

        component.$wire.$call.mockImplementation(async () => {
            effectsFor(component, { qty: ['The qty field must not be greater than 5.'], productId: ['The product id field is required.'] });
        });

        const result = await mc.call('cart.add-to-cart', { productId: 1, qty: 9 });

        expect(result).toEqual({
            content: [{ type: 'text', text: 'The qty field must not be greater than 5. The product id field is required.' }],
            isError: true,
        });
    });

    it('ignores effects of other components', async () => {
        const component = makeComponent('c1', [action()]);
        const other = makeComponent('c2', []);
        init(component);
        await flush();

        component.$wire.$call.mockImplementation(async () => {
            effectsFor(other, { qty: ['not ours'] });

            return 'fine';
        });

        expect(await mc.call('cart.add-to-cart', { productId: 1 })).toEqual({ content: [{ type: 'text', text: 'fine' }] });
    });

    it('stops listening once the call is over', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();
        const before = livewire.hooks.commit.length;

        component.$wire.$call.mockResolvedValue('ok');
        await mc.call('cart.add-to-cart', { productId: 1 });

        expect(livewire.hooks.commit.length).toBe(before);
    });

    it('stops listening when the call is rejected', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();
        const before = livewire.hooks.commit.length;

        component.$wire.$call.mockRejectedValue({ status: 403 });
        await mc.call('cart.add-to-cart', { productId: 1 });

        expect(livewire.hooks.commit.length).toBe(before);
    });

    it('a later successful call is not blamed for an earlier validation failure', async () => {
        const component = makeComponent('c1', [action()]);
        init(component);
        await flush();

        component.$wire.$call.mockImplementationOnce(async () => effectsFor(component, { qty: ['bad'] }));
        expect((await mc.call('cart.add-to-cart', { productId: 1, qty: 9 })).isError).toBe(true);

        component.$wire.$call.mockResolvedValueOnce('ok');
        expect((await mc.call('cart.add-to-cart', { productId: 1, qty: 1 })).isError).toBeUndefined();
    });
});

describe('Livewire 4 message interceptor', () => {
    it('reads the webmcpErrors effect through $interceptMessage and cleans up', async () => {
        const component = makeComponent('c1', [action()]);
        const interceptors = [];
        const off = vi.fn();
        component.$wire.$interceptMessage = (callback) => {
            interceptors.push(callback);

            return off;
        };
        init(component);
        await flush();

        component.$wire.$call.mockImplementation(async () => {
            interceptors.forEach((callback) => callback({
                onSuccess: (fn) => fn({ payload: { effects: { webmcpErrors: { qty: ['The qty field must not be greater than 5.'] } } } }),
            }));
        });

        const result = await mc.call('cart.add-to-cart', { productId: 1, qty: 9 });

        expect(result).toEqual({ content: [{ type: 'text', text: 'The qty field must not be greater than 5.' }], isError: true });
        expect(off).toHaveBeenCalled();
    });
});

describe('a throwing interceptor remover (Livewire 4.4)', () => {
    it('does not break the call and the interceptor stops capturing', async () => {
        const component = makeComponent('c1', [action()]);
        const interceptors = [];
        component.$wire.$interceptMessage = (callback) => {
            interceptors.push(callback);

            return () => {
                throw new TypeError('delete is not a function');
            };
        };
        init(component);
        await flush();

        component.$wire.$call.mockResolvedValue('ok');
        expect(await mc.call('cart.add-to-cart', { productId: 1 })).toEqual({ content: [{ type: 'text', text: 'ok' }] });

        // the interceptor of the finished call is still registered but inert: a later error must not leak into the next call
        component.$wire.$call.mockImplementation(async () => {
            interceptors.slice(0, 1).forEach((callback) => callback({
                onSuccess: (fn) => fn({ payload: { effects: { webmcpErrors: { qty: ['old'] } } } }),
            }));

            return 'fine';
        });

        expect((await mc.call('cart.add-to-cart', { productId: 1 })).isError).toBeUndefined();
    });
});
