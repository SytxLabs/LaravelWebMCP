<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>laravel-webmcp workbench</title>
    <link rel="stylesheet" href="/vendor/webmcp/webmcp-forms.css">
    <style>
        body {
            font-family: system-ui, sans-serif;
            max-width: 52rem;
            margin: 2rem auto;
            padding: 0 1rem;
            line-height: 1.5;
        }

        section {
            border: 1px solid #ccc;
            border-radius: .5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        h2 {
            margin-top: 0;
        }

        .webmcp-field {
            margin-bottom: .5rem;
        }

        label {
            display: inline-block;
            min-width: 8rem;
        }

        #log {
            background: #111;
            color: #9f9;
            padding: .5rem;
            font: 12px/1.4 ui-monospace, monospace;
            max-height: 16rem;
            overflow: auto;
            white-space: pre-wrap;
        }
    </style>

    <script type="module" src="/vendor/webmcp/webmcp-livewire.js"></script>
    <script type="module" src="/vendor/webmcp/webmcp-alpine.js"></script>
    <script type="module" src="/vendor/webmcp/webmcp-forms.js"></script>
</head>
<body>
    {{-- Imperative tools: the manifest of the current user plus the runtime (once). In the BODY, not the head:
         wire:navigate / Turbo keep old <head> elements, so a manifest there would outlive its page. --}}
    @webmcp(\Workbench\App\Mcp\ShopServer::class)

<h1>laravel-webmcp workbench</h1>
<p>
    WebMCP support: <strong id="support">checking...</strong>.
    Enable it in Chrome with <code>chrome://flags/#enable-webmcp-testing</code>.
    User: <strong id="user">{{ auth()->check() ? 'logged in' : 'guest' }}</strong>
    <button type="button" id="toggle-login">{{ auth()->check() ? 'Log out' : 'Log in' }}</button>
</p>

<section>
    <h2>1. Imperative tools (Blade directive)</h2>
    <p>Registered from the manifest: <code id="tools">...</code></p>
</section>

<section>
    <h2>2. Declarative form</h2>
    <x-webmcp::form :tool="\Workbench\App\Mcp\Tools\SearchProductsTool::class" action="/search" method="get" submit="Search"
                    id="search-form"/>
</section>

<section>
    <h2>3. Livewire actions</h2>
    <livewire:cart/>
</section>

<section>
    <h2>4. Alpine <code>x-webmcp</code></h2>
    <div x-data="{ count: 0 }">
            <span x-webmcp="{
                name: 'counter.increment',
                description: 'Increments the counter on this page',
                inputSchema: { type: 'object', properties: { by: { type: 'integer', description: 'Step' } } },
                execute: ({ by = 1 }) => { count += by; return 'count is ' + count },
            }"></span>
        Counter: <strong data-testid="count" x-text="count"></strong>
        <button type="button" @click="count++">+1</button>
    </div>
</section>

<section>
    <h2>5. SPA navigation</h2>
    <a href="/second" wire:navigate id="to-second">Go to the second page (wire:navigate)</a>
</section>

<section>
    <h2>Event log</h2>
    <div id="log"></div>
</section>

<script type="module">
    // Module scripts keep running after wire:navigate: the page elements may be gone by then.
    const write = (name, detail) => {
        const log = document.getElementById('log');
        if (!log) return;
        log.textContent += name + ' ' + JSON.stringify(detail) + '\n';
        log.scrollTop = log.scrollHeight;
    };

    for (const name of ['registered', 'unregistered', 'invoked', 'succeeded', 'failed', 'refreshed', 'error', 'skipped', 'unsupported']) {
        document.addEventListener('webmcp:' + name, (event) => write(name, event.detail));
    }

    const supported = 'modelContext' in document || 'modelContext' in navigator;
    const supportEl = document.getElementById('support');
        if (supportEl) supportEl.textContent = supported ? 'available' : 'not available (no-op)';

    const refreshTools = () => {
        const names = window.WebMcp ? window.WebMcp.servers().flatMap((slug) => window.WebMcp.tools(slug)) : [];
        const target = document.getElementById('tools');
        if (target) target.textContent = names.join(', ') || '(none)';
    };

    document.addEventListener('webmcp:registered', refreshTools);
    document.addEventListener('webmcp:unregistered', refreshTools);
    document.addEventListener('webmcp:refreshed', refreshTools);

    document.getElementById('toggle-login').addEventListener('click', async () => {
        const loggedIn = document.getElementById('user').textContent === 'logged in';
        await fetch('/demo/' + (loggedIn ? 'logout' : 'login'), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                Accept: 'application/json'
            },
            credentials: 'same-origin',
        });
        document.getElementById('user').textContent = loggedIn ? 'guest' : 'logged in';
        document.getElementById('toggle-login').textContent = loggedIn ? 'Log in' : 'Log out';
        // Login/logout without reload: tell the runtime to reconcile the tools for the new user.
        document.dispatchEvent(new CustomEvent('webmcp:auth-changed'));
    });

    setTimeout(refreshTools, 300);
</script>
</body>
</html>
