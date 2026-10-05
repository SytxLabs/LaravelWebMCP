import Alpine from 'alpinejs';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { WebMcp, resetForTests } from '../resources/js/webmcp.js';
import webmcpAlpine from '../resources/js/webmcp-alpine.js';
import { installDocumentModelContext, MockModelContext, removeDocumentModelContext } from './mock-model-context.js';

const flush = async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setTimeout(resolve, 0));
};

let mc;

beforeAll(() => {
    window.Alpine = Alpine;
    Alpine.plugin(webmcpAlpine);
    Alpine.start();
});

beforeEach(() => {
    mc = installDocumentModelContext(new MockModelContext());
});

afterEach(async () => {
    document.body.replaceChildren();
    await flush();
    resetForTests();
    removeDocumentModelContext();
    vi.restoreAllMocks();
});

function mount(html) {
    document.body.insertAdjacentHTML('beforeend', html);

    return document.body.lastElementChild;
}

describe('x-webmcp', () => {
    it('registers a tool when the element initializes', async () => {
        mount(`<div x-data="{ count: 0 }"><span x-webmcp="{
            name: 'counter.increment',
            title: 'Increment',
            description: 'Increments the counter',
            inputSchema: { type: 'object', properties: { by: { type: 'integer' } } },
            annotations: { readOnlyHint: false },
            execute: ({ by = 1 }) => { count += by; return 'count is ' + count },
        }"></span></div>`);
        await flush();

        expect(mc.names()).toEqual(['counter.increment']);

        const { tool } = mc.registerCalls[0];

        expect(tool.title).toBe('Increment');
        expect(tool.inputSchema.properties.by.type).toBe('integer');
    });

    it('runs execute with args and an AbortSignal, with access to the component state', async () => {
        const root = mount(`<div x-data="{ count: 0 }"><span x-webmcp="{
            name: 'counter.increment',
            description: 'Increments the counter',
            execute: ({ by = 1 }, { signal }) => { count += by; return { count, hasSignal: signal instanceof AbortSignal } },
        }"></span></div>`);
        await flush();

        const result = await mc.call('counter.increment', { by: 4 });

        expect(JSON.parse(result.content[0].text)).toEqual({ count: 4, hasSignal: true });
        expect(Alpine.$data(root).count).toBe(4);
    });

    it('maps return values and passes {content} through', async () => {
        mount(`<div x-data><span x-webmcp="{ name: 'plain', description: 'd', execute: () => 'hello' }"></span>
            <span x-webmcp="{ name: 'nothing', description: 'd', execute: () => {} }"></span>
            <span x-webmcp="{ name: 'raw', description: 'd', execute: () => ({ content: [{ type: 'text', text: 'raw' }], isError: false }) }"></span></div>`);
        await flush();

        expect((await mc.call('plain')).content[0].text).toBe('hello');
        expect((await mc.call('nothing')).content[0].text).toBe('Done.');
        expect((await mc.call('raw')).content[0].text).toBe('raw');
    });

    it('aborts (unregisters) the tool when the element is removed', async () => {
        const root = mount('<div x-data><span x-webmcp="{ name: \'temp\', description: \'d\', execute: () => 1 }"></span></div>');
        await flush();

        const signal = mc.registerCalls[0].options.signal;
        expect(mc.names()).toEqual(['temp']);

        root.remove();
        await flush();

        expect(signal.aborted).toBe(true);
        expect(mc.names()).toEqual([]);
        expect(WebMcp.servers().filter((id) => id.startsWith('alpine:'))).toEqual([]);
    });

    it('re-registers when the definition changes reactively and keeps calling the newest execute()', async () => {
        const root = mount(`<div x-data="{ label: 'one' }"><span x-webmcp="{
            name: 'dyn',
            description: 'Description ' + label,
            execute: () => label,
        }"></span></div>`);
        await flush();

        expect(mc.tools.get('dyn').tool.description).toBe('Description one');

        Alpine.$data(root).label = 'two';
        await flush();

        expect(mc.tools.get('dyn').tool.description).toBe('Description two');
        expect(mc.registerCalls).toHaveLength(2);
        expect((await mc.call('dyn')).content[0].text).toBe('two');
    });

    it('does not re-register when only execute() output would change', async () => {
        const root = mount('<div x-data="{ n: 0 }"><span x-webmcp="{ name: \'stable\', description: \'d\', execute: () => n }"></span></div>');
        await flush();

        Alpine.$data(root).n = 5;
        await flush();

        expect(mc.registerCalls).toHaveLength(1);
    });

    it('turns exceptions in execute() into error results', async () => {
        mount('<div x-data><span x-webmcp="{ name: \'boom\', description: \'d\', execute: () => { throw new Error(\'it broke\') } }"></span></div>');
        await flush();

        expect(await mc.call('boom')).toEqual({ content: [{ type: 'text', text: 'it broke' }], isError: true });
    });

    it('asks the confirm hook for confirm:true tools', async () => {
        const confirm = vi.fn().mockResolvedValue(false);
        WebMcp.configure({ confirm });
        mount('<div x-data><span x-webmcp="{ name: \'danger\', description: \'d\', confirm: true, execute: () => \'ran\' }"></span></div>');
        await flush();

        const result = await mc.call('danger');

        expect(result.isError).toBe(true);
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(mc.registerCalls[0].tool).not.toHaveProperty('confirm');
    });

    it.each([
        ['missing name', '{ description: \'d\', execute: () => 1 }'],
        ['missing description', '{ name: \'x\', execute: () => 1 }'],
        ['missing execute', '{ name: \'x\', description: \'d\' }'],
        ['not an object', '\'nope\''],
    ])('logs and skips an invalid definition (%s)', async (_label, expression) => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        mount(`<div x-data><span x-webmcp="${expression}"></span></div>`);
        await flush();

        expect(mc.names()).toEqual([]);
        expect(error).toHaveBeenCalled();
    });

    it('reports duplicate names as an event instead of breaking', async () => {
        const errors = [];
        WebMcp.on('error', (event) => errors.push(event.detail));
        mount(`<div x-data><span x-webmcp="{ name: 'same', description: 'd', execute: () => 1 }"></span>
            <span x-webmcp="{ name: 'same', description: 'd', execute: () => 2 }"></span></div>`);
        await flush();

        expect(mc.names()).toEqual(['same']);
        expect(errors[0]).toMatchObject({ name: 'InvalidStateError' });
    });
});
