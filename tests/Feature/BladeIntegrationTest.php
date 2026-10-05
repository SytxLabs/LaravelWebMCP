<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\ViewException;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;
use SytxLabs\LaravelWebMcp\View\NonceResolver;

beforeEach(function () {
    WebMcp::server('exec', Fixtures\ExecServer::class, endpoint: '/mcp/exec');
    WebMcp::server('hostile', Fixtures\HostileServer::class);
    NonceResolver::using(null);
});

afterEach(function () {
    NonceResolver::using(null);
});

/** @return array<string, mixed> the decoded manifest embedded for a server */
function embedded(string $html, string $slug): array
{
    preg_match('#<script type="application/json" id="webmcp-manifest-'.$slug.'"[^>]*>(.*?)</script>#s', $html, $match);

    return json_decode($match[1] ?? 'null', true, flags: JSON_THROW_ON_ERROR);
}

it('renders the manifest as application/json and the runtime once through the directive', function () {
    $html = Blade::render('@webmcp("exec")');

    expect($html)->toContain('<script type="application/json" id="webmcp-manifest-exec" data-webmcp-manifest')
        ->and($html)->toContain('<script type="module" src="')
        ->and(substr_count($html, 'type="module"'))->toBe(1);

    $payload = embedded($html, 'exec');

    expect($payload['version'])->toBe(1)
        ->and($payload['server'])->toBe('exec')
        ->and($payload['endpoints'])->toMatchArray([
            'manifest' => '/webmcp/exec/manifest',
            'tools' => '/webmcp/exec/tools/{name}',
            'resources' => '/webmcp/exec/resources/{name}',
            'bridge' => '/mcp/exec',
        ])
        ->and(array_column($payload['tools'], 'name'))->toContain('weather-tool');
});

it('resolves a server by class and renders several servers with one runtime tag', function () {
    $html = Blade::render('@webmcp([\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\ExecServer::class, "hostile"])');

    expect($html)->toContain('id="webmcp-manifest-exec"')
        ->and($html)->toContain('id="webmcp-manifest-hostile"')
        ->and(substr_count($html, 'type="module"'))->toBe(1);
});

it('renders every registered server without arguments', function () {
    $html = Blade::render('@webmcp');

    expect($html)->toContain('id="webmcp-manifest-exec"')->toContain('id="webmcp-manifest-hostile"');
});

it('fails clearly for an unknown server', function () {
    Blade::render('@webmcp("nope")');
})->throws(ViewException::class, 'WebMCP server [nope] is not registered');

it('works as a component and shares the runtime tag with the directive', function () {
    $html = Blade::render('<x-webmcp::tools server="exec" /> @webmcp("hostile")');

    expect($html)->toContain('id="webmcp-manifest-exec"')
        ->and($html)->toContain('id="webmcp-manifest-hostile"')
        ->and(substr_count($html, 'type="module"'))->toBe(1);
});

it('can omit the runtime tag', function () {
    expect(Blade::render('@webmcp("exec", false)'))->not->toContain('type="module"');
    expect(Blade::render('<x-webmcp::tools server="exec" :script="false" />'))->not->toContain('type="module"');

    config(['webmcp.assets.script' => false]);
    expect(Blade::render('@webmcp("exec")'))->not->toContain('type="module"');
});

it('does not emit anything for a server without exposed tools beyond an empty manifest', function () {
    config(['webmcp.enabled' => false]);

    $payload = embedded(Blade::render('@webmcp("exec")'), 'exec');

    expect($payload['tools'])->toBe([]);
});

it('embeds hostile tool metadata safely (script breakout, Blade syntax, quotes)', function () {
    $html = Blade::render('@webmcp("hostile")');

    // No way out of the script element
    expect($html)->not->toContain('<script>alert(1)')
        ->and($html)->not->toContain('</script><script>alert')
        ->and(substr_count($html, '</script>'))->toBe(2); // manifest element + runtime element only

    // Round trip is lossless
    $description = embedded($html, 'hostile')['tools'][0]['description'];

    expect($description)->toContain('</script><script>alert(1)</script>')
        ->and($description)->toContain('{{ 1 + 1 }}')
        ->and($description)->toContain('\'quotes\'')
        ->and($description)->toContain('"double"');

    // Escaped on the wire
    // (backslash built with chr() so no tool layer rewrites the escape sequences)
    $bs = chr(92);
    expect($html)->toContain($bs.'u003C/script'.$bs.'u003E')->and($html)->toContain($bs.'u0026');
});

it('never compiles manifest content as Blade, not even in the component', function () {
    $html = Blade::render('<x-webmcp::tools server="hostile" />');

    expect($html)->toContain('{{ 1 + 1 }}')
        ->and($html)->not->toContain('<script>alert(1)')
        ->and(embedded($html, 'hostile')['tools'][0]['description'])->toContain('{{ 1 + 1 }}')
        ->and($html)->not->toContain('x"x'); // @php block did not run
});

it('adds the Vite CSP nonce to the manifest and runtime tags', function () {
    Vite::useCspNonce('vite-nonce-1');

    $html = Blade::render('@webmcp("exec")');

    expect($html)->toMatch('/data-webmcp-manifest\s+nonce="vite-nonce-1"/')
        ->and($html)->toContain('<script type="module"')
        ->and(substr_count($html, 'nonce="vite-nonce-1"'))->toBe(2);
});

it('supports a custom nonce source and escapes it', function () {
    WebMcp::nonceUsing(fn () => 'custom"><x');

    $html = Blade::render('@webmcp("exec")');

    expect($html)->toContain('nonce="custom&quot;&gt;&lt;x"')->and($html)->not->toContain('nonce="custom"><x"');
});

it('adds no nonce attribute without a nonce source', function () {
    expect(Blade::render('@webmcp("exec")'))->not->toContain('nonce=');
});

it('keeps class names, paths and secrets out of the embedded payload', function () {
    $json = json_encode(embedded(Blade::render('@webmcp("exec")'), 'exec'));

    expect($json)->not->toContain('SytxLabs')
        ->and($json)->not->toContain('Fixtures')
        ->and($json)->not->toContain('.php')
        ->and($json)->not->toContain('"source"')
        ->and($json)->not->toContain('"mcpName"')
        ->and($json)->not->toContain('APP_KEY')
        ->and($json)->not->toContain('allowedUris');
});

it('includes Bridge routing information only for Bridge-mode tools', function () {
    $tools = collect(embedded(Blade::render('@webmcp("exec")'), 'exec')['tools'])->keyBy('name');

    expect($tools['custom.name']['bridge'])->toBe(['method' => 'tools/call', 'name' => 'renamed-tool'])
        ->and($tools['read-bridge-resource']['bridge'])->toBe(['method' => 'resources/read', 'uri' => 'file://resources/bridge'])
        ->and($tools['weather-tool'])->not->toHaveKey('bridge');
});

it('renders the manifest of the current user', function () {
    $guest = array_column(embedded(Blade::render('@webmcp("exec")'), 'exec')['tools'], 'name');

    $this->actingAs(new GenericUser(['id' => 1]));

    $user = array_column(embedded(Blade::render('@webmcp("exec")'), 'exec')['tools'], 'name');

    expect($guest)->not->toContain('authed-tool')->and($user)->toContain('authed-tool');
});

it('detects tool name collisions across servers rendered on one page', function () {
    WebMcp::server('again', Fixtures\WeatherOnlyServer::class);

    Blade::render('@webmcp(["exec", "again"])');
})->throws(ViewException::class, 'weather-tool');

it('exposes the confirmation and error conventions to the runtime', function () {
    config(['webmcp.confirmation.server_enforced' => true, 'webmcp.errors.mode' => 'reject']);

    $payload = embedded(Blade::render('@webmcp("exec")'), 'exec');

    expect($payload['errors'])->toBe('reject')
        ->and($payload['confirmation'])->toBe(['serverEnforced' => true, 'header' => 'X-WebMCP-Confirmation'])
        ->and($payload['header'])->toBe('X-WebMCP');
});

it('only allows the same-origin path of a Bridge endpoint', function () {
    WebMcp::server('foreign', Fixtures\WeatherOnlyServer::class, endpoint: 'https://evil.example.com/mcp/x');

    $payload = embedded(Blade::render('@webmcp("foreign")'), 'foreign');

    expect($payload['endpoints']['bridge'])->toBe('/mcp/x');
});

it('limits the tools of one page across all servers and logs what was dropped', function () {
    Log::spy();
    WebMcp::server('shop', Fixtures\FormServer::class);
    config(['webmcp.limits.max_tools_per_page' => 4]);

    $html = Blade::render('@webmcp(["exec", "shop"])');

    $exec = embedded($html, 'exec')['tools'];
    $shop = embedded($html, 'shop')['tools'];

    expect(count($exec) + count($shop))->toBe(4)
        ->and(count($exec))->toBe(4)   // the first server uses the whole budget
        ->and($shop)->toBe([]);        // later servers get nothing once it is spent

    Log::shouldHaveReceived('warning')->once();
});

it('does not limit the page when the page limit is 0', function () {
    config(['webmcp.limits.max_tools_per_page' => 0]);

    $count = count(embedded(Blade::render('@webmcp("exec")'), 'exec')['tools']);

    expect($count)->toBeGreaterThan(10);
});
