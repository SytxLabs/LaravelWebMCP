// End-to-end test in a real Chrome with WebMCP enabled, against the workbench (real server, Livewire, Alpine).
//
//   npm run e2e
//
// Environment: E2E_PHP (php binary, default "php"), E2E_PORT (default 8777), CHROME_PATH (default: the installed Chrome).
// The test skips itself when this Chrome does not expose document.modelContext with the flag below.
import { spawn, spawnSync } from 'node:child_process';
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';

const PHP = process.env.E2E_PHP || 'php';
const PORT = process.env.E2E_PORT || '8777';
const BASE = `http://127.0.0.1:${PORT}`;
const CHROME_FLAGS = ['--enable-features=WebMCPTesting'];

let server;
let browser;
let supported = false;

async function waitForServer() {
    for (let attempt = 0; attempt < 60; attempt++) {
        try {
            const response = await fetch(`${BASE}/`);

            if (response.ok) {
                return;
            }
        } catch {
            // not up yet
        }

        await new Promise((resolve) => setTimeout(resolve, 500));
    }

    throw new Error('The workbench server did not start.');
}

before(async () => {
    const build = spawnSync(PHP, ['vendor/bin/testbench', 'workbench:build'], { stdio: 'inherit', shell: true });

    assert.equal(build.status, 0, 'workbench:build failed');

    server = spawn(PHP, ['vendor/bin/testbench', 'serve', '--host=127.0.0.1', `--port=${PORT}`, '--no-reload'], { stdio: 'ignore', shell: true });
    await waitForServer();

    browser = await chromium.launch(
        process.env.CHROME_PATH
            ? { executablePath: process.env.CHROME_PATH, args: CHROME_FLAGS }
            : { channel: 'chrome', args: CHROME_FLAGS },
    );

    const probe = await browser.newPage();
    await probe.goto(`${BASE}/`);
    supported = await probe.evaluate(() => 'modelContext' in document);
    await probe.close();
});

after(async () => {
    await browser?.close();

    if (server) {
        // `shell: true` wraps the process; kill the whole tree.
        if (process.platform === 'win32') {
            spawnSync('taskkill', ['/pid', String(server.pid), '/T', '/F'], { stdio: 'ignore' });
        } else {
            server.kill('SIGTERM');
        }
    }
});

/** Names of the tools the page registered, as the browser reports them (real getTools()). */
const toolNames = (page) => page.evaluate(async () => (await document.modelContext.getTools()).map((tool) => tool.name).sort());

/** Runs a tool through the browser's own executeTool() and parses the JSON result. */
const run = (page, name, input = {}) => page.evaluate(async ({ name, input }) => {
    const tool = (await document.modelContext.getTools()).find((candidate) => candidate.name === name);

    if (!tool) {
        return { __missing: name };
    }

    // The spec IDL says `object inputObject`; Chrome 154 expects the arguments as a JSON string. Try the string
    // first and fall back to the object, so the harness follows the spec once browsers do.
    for (const argument of [JSON.stringify(input), input]) {
        try {
            return JSON.parse(await document.modelContext.executeTool(tool, argument));
        } catch (error) {
            if (!/parse input arguments/i.test(error.message)) {
                return { __rejected: error.name, message: error.message };
            }
        }
    }

    return { __rejected: 'UnknownError', message: 'executeTool rejected both argument forms' };
}, { name, input });

/** Polls the browser's real getTools() until a tool is (or is no longer) registered. */
async function waitForTool(page, name, present, timeout = 8000) {
    const deadline = Date.now() + timeout;

    while (Date.now() < deadline) {
        const names = await toolNames(page);

        if (names.includes(name) === present) {
            return;
        }

        await page.waitForTimeout(200);
    }

    throw new Error(`Tool ${name} did not ${present ? 'appear' : 'disappear'} within ${timeout} ms.`);
}

/** Opens the workbench with the confirm hook accepting everything and collects page errors. */
async function open(path = '/') {
    const page = await browser.newPage();
    const errors = [];

    page.on('pageerror', (error) => errors.push(error.message));
    page.on('dialog', (dialog) => dialog.accept());
    await page.goto(`${BASE}${path}`);
    await page.waitForFunction(() => window.WebMcp && window.Livewire && window.Alpine);
    await page.waitForTimeout(600);

    return { page, errors };
}

describe('workbench in Chrome with WebMCP', () => {
    it('exposes document.modelContext (otherwise the rest is skipped)', (t) => {
        if (!supported) {
            t.skip('This Chrome does not expose document.modelContext with the WebMCP testing flag.');
        }

        assert.ok(true);
    });

    it('registers server tools, Livewire actions and the Alpine tool in the browser', async (t) => {
        if (!supported) return t.skip('WebMCP not available');

        const { page, errors } = await open();
        const names = await toolNames(page);

        for (const expected of ['read-catalog-resource', 'read-product-resource', 'cart.add-to-cart', 'cart.clear', 'cart.list', 'counter.increment']) {
            assert.ok(names.includes(expected), `${expected} is registered (got ${names.join(', ')})`);
        }

        assert.ok(!names.includes('place-order-tool'), 'the consequential order tool is hidden from guests');
        assert.deepEqual(errors, [], 'no page errors, no unhandled rejections');
        await page.close();
    });

    it('runs the Alpine tool and updates the page', async (t) => {
        if (!supported) return t.skip('WebMCP not available');

        const { page } = await open();
        const result = await run(page, 'counter.increment', { by: 3 });

        assert.equal(result.content[0].text, 'count is 3');
        assert.equal(await page.textContent('[data-testid=count]'), '3');
        await page.close();
    });

    it('runs Livewire actions through $wire.$call and reports validation errors', async (t) => {
        if (!supported) return t.skip('WebMCP not available');

        const { page } = await open();

        const added = await run(page, 'cart.add-to-cart', { productId: 1, qty: 2 });
        assert.match(added.content[0].text, /Added 2 x Trail Runner/);
        assert.match(await page.innerText('[data-testid=cart-items]'), /2 x Trail Runner/);

        const invalid = await run(page, 'cart.add-to-cart', { productId: 1, qty: 9 });
        assert.equal(invalid.isError, true);
        assert.match(invalid.content[0].text, /must not be greater than 5/);

        const cleared = await run(page, 'cart.clear');
        assert.match(cleared.content[0].text, /empty/);
        assert.match(await page.innerText('[data-testid=cart-items]'), /Empty/);
        await page.close();
    });

    it('reads resources over Session and Bridge mode and refuses traversal and unauthorized reads', async (t) => {
        if (!supported) return t.skip('WebMCP not available');

        const { page } = await open();

        assert.match((await run(page, 'read-catalog-resource')).content[0].text, /Trail Runner/);   // Bridge
        assert.match((await run(page, 'read-product-resource', { productId: '3' })).content[0].text, /Wool Hat/);   // Session

        const traversal = await run(page, 'read-product-resource', { productId: '../3' });
        assert.equal(traversal.isError, true);

        const unknown = await run(page, 'read-product-resource', { productId: '99' });
        assert.equal(unknown.isError, true);
        await page.close();
    });

    it('follows login and logout without a reload: the order tool appears and disappears', async (t) => {
        if (!supported) return t.skip('WebMCP not available');

        const { page, errors } = await open();

        await page.click('#toggle-login');
        await waitForTool(page, 'place-order-tool', true);

        const order = await run(page, 'place-order-tool', { product_id: 1, quantity: 2 });
        assert.ok(order.content, JSON.stringify(order));
        assert.match(order.content[0].text, /Ordered 2 x Trail Runner/);

        await page.click('#toggle-login');
        await waitForTool(page, 'place-order-tool', false);

        assert.deepEqual(errors, [], 'unregistering through abort() leaves no unhandled rejection');
        await page.close();
    });

    it('runs the declarative form: Chrome fills it, auto-submits (read-only tool) and our respondWith answers', async (t) => {
        if (!supported) return t.skip('WebMCP not available');

        const { page, errors } = await open();
        const names = await toolNames(page);

        assert.ok(names.includes('search-products-tool'), `the declarative form is a tool (got ${names.join(', ')})`);

        const result = await run(page, 'search-products-tool', { query: 'hat', in_stock: true });
        const text = result.content?.[0]?.text ?? JSON.stringify(result);

        assert.equal(JSON.parse(text).count, 2, text);
        assert.equal(new URL(page.url()).pathname, '/', 'agent submits do not navigate');
        assert.deepEqual(errors, []);
        await page.close();
    });

    it('follows wire:navigate: tools of the old page go away, those of the new page appear, and back again', async (t) => {
        if (!supported) return t.skip('WebMCP not available');

        const { page, errors } = await open();

        assert.ok((await toolNames(page)).includes('read-catalog-resource'), 'first page registers the shop server');

        await page.click('#to-second');
        await page.waitForURL(`${BASE}/second`);
        await waitForTool(page, 'read-catalog-resource', false);

        let names = await toolNames(page);
        assert.ok(!names.includes('counter.increment'), 'the Alpine tool left with its element');
        assert.ok(names.includes('cart.add-to-cart'), `the new Livewire instance registers its actions (got ${names.join(', ')})`);
        assert.equal(names.filter((name) => name === 'cart.add-to-cart').length, 1, 'no duplicates after navigating');

        await page.click('#to-first');
        await page.waitForURL(`${BASE}/`);
        await waitForTool(page, 'read-catalog-resource', true);

        names = await toolNames(page);
        assert.ok(names.includes('counter.increment'), 'the Alpine tool is back');
        assert.equal(names.filter((name) => name === 'cart.add-to-cart').length, 1, 'still exactly one cart instance');
        assert.deepEqual(errors, [], 'navigation leaves no unhandled rejection: ' + errors.join(' | '));
        await page.close();
    });

    it('keeps the page working when WebMCP is disabled by Permissions-Policy tools=()', async (t) => {
        if (!supported) return t.skip('WebMCP not available');

        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));

        await page.goto(`${BASE}/no-tools`);
        await page.waitForFunction(() => window.WebMcp);
        await page.waitForTimeout(800);

        const log = await page.textContent('#log');
        const result = await page.evaluate(async () => {
            try {
                return { names: (await document.modelContext.getTools()).map((tool) => tool.name) };
            } catch (error) {
                return { rejected: error.name };
            }
        });

        assert.ok(result.rejected === 'NotAllowedError' || result.names?.length === 0, JSON.stringify(result));
        assert.match(log, /NotAllowedError/);
        assert.deepEqual(errors, [], 'a disabled WebMCP must not break the page');
        await page.close();
    });
});
