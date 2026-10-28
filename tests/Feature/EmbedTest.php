<?php

declare(strict_types=1);

use Laravel\Mcp\Facades\Mcp;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Http\Middleware\EnforceWebMcpExposure;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;

const SHOP = 'https://shop.example.com';

beforeEach(function () {
    WebMcp::server('exec', Fixtures\ExecServer::class, endpoint: '/mcp/exec');
    Mcp::web('/mcp/exec', Fixtures\ExecServer::class)->middleware(EnforceWebMcpExposure::class.':exec');
    config(['webmcp.embed.servers' => ['exec'], 'webmcp.embed.origins' => [SHOP]]);
});

afterEach(function () {
    WebMcp::embedOriginsUsing(null);
});

it('is off until servers and origins are configured', function (array $config) {
    config($config);

    $this->get('/webmcp/exec/embed.js', ['Origin' => SHOP])->assertNotFound();
    $this->get('/webmcp/exec/embed/manifest', ['Origin' => SHOP])->assertNotFound();
    $this->get('/webmcp/_assets/webmcp-core.js', ['Origin' => SHOP])->assertNotFound();
    $this->options('/mcp/exec', [], ['Origin' => SHOP, 'Access-Control-Request-Method' => 'POST'])->assertHeaderMissing('Access-Control-Allow-Origin');
})->with([
    'no servers' => [['webmcp.embed.servers' => []]],
    'no origins' => [['webmcp.embed.origins' => []]],
    'insecure origin only' => [['webmcp.embed.origins' => ['http://shop.example.com']]],
    'unregistered server' => [['webmcp.embed.servers' => ['nope']]],
]);

it('serves the loader as a CORS module that resolves everything against its own URL', function () {
    $response = $this->get('/webmcp/exec/embed.js', ['Origin' => SHOP]);

    $response->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', SHOP)
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Type'))->toStartWith('text/javascript')
        ->and($response->headers->get('Vary'))->toContain('Origin')
        ->and($response->getContent())->toContain("from '../_assets/webmcp-core.js?v=")
        ->and($response->getContent())->toContain("embed(new URL('./embed/manifest', import.meta.url).href)");
    $this->get('/webmcp/_assets/webmcp-core.js', ['Origin' => SHOP])->assertOk()->assertHeader('Access-Control-Allow-Origin', SHOP);
});

it('refuses origins that are not allowed and unknown servers', function () {
    $this->get('/webmcp/exec/embed.js', ['Origin' => 'https://evil.example'])->assertForbidden()->assertHeaderMissing('Access-Control-Allow-Origin');
    $this->get('/webmcp/weather/embed.js', ['Origin' => SHOP])->assertNotFound();
});

it('serves a guest manifest with only Bridge tools, absolute endpoints, no CSRF token and no session', function () {
    $response = $this->get('/webmcp/exec/embed/manifest', ['Origin' => SHOP]);

    $response->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', SHOP)
        ->assertJsonPath('server', 'exec')
        ->assertJsonPath('csrf', null)
        ->assertJsonPath('endpoints.bridge', 'http://localhost/mcp/exec')
        ->assertJsonPath('endpoints.manifest', 'http://localhost/webmcp/exec/embed/manifest');
    expect($response->json('endpoints'))->not->toHaveKeys(['tools', 'resources'])
        ->and(collect($response->json('tools'))->pluck('mode')->unique()->all())->toBe(['bridge'])
        ->and(collect($response->json('tools'))->pluck('name'))->toContain('custom.name')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=60')
        ->and($response->headers->getCookies())->toBe([]);
});

it('answers the preflight of the Bridge endpoint for allowed origins only, without credentials', function () {
    $response = $this->options('/mcp/exec', [], ['Origin' => SHOP, 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type, x-webmcp']);

    $response->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', SHOP)
        ->assertHeader('Access-Control-Allow-Methods', 'GET, POST')
        ->assertHeader('Access-Control-Max-Age', '600')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
    expect($response->headers->get('Access-Control-Allow-Headers'))->toContain('X-WebMCP')->toContain('Content-Type');

    $this->options('/mcp/exec', [], ['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST'])->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('adds CORS to Bridge calls and keeps the exposure guard in charge', function () {
    $call = fn (string $name) => $this->postJson('/mcp/exec', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) []]], ['Origin' => SHOP, 'X-WebMCP' => '1']);

    $call('renamed-tool')->assertOk()->assertHeader('Access-Control-Allow-Origin', SHOP)->assertJsonPath('result.content.0.text', 'renamed');
    $call('weather-tool')->assertForbidden()->assertHeader('Access-Control-Allow-Origin', SHOP);
});

it('leaves paths outside embed mode alone', function () {
    $response = $this->get('/webmcp/exec/manifest', ['Origin' => SHOP]);

    $response->assertHeaderMissing('Access-Control-Allow-Origin');
    expect((string) $response->headers->get('Vary'))->not->toContain('Origin');
});

it('takes origins from a resolver per request', function () {
    config(['webmcp.embed.origins' => []]);
    WebMcp::embedOriginsUsing(fn ($request) => $request->getHost() === 'localhost' ? ['https://tenant.example', 42, 'ftp://x'] : []);

    $this->get('/webmcp/exec/embed.js', ['Origin' => 'https://tenant.example'])->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://tenant.example');
    $this->get('/webmcp/exec/embed.js', ['Origin' => SHOP])->assertForbidden();
});

it('lets requests without Origin pass (same-origin or tools like curl)', function () {
    $this->get('/webmcp/exec/embed/manifest')->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('tells the browser whether to keep structuredContent of Bridge results', function () {
    $this->get('/webmcp/exec/embed/manifest')->assertJsonPath('structuredContent', false);

    config(['webmcp.features.structured_content' => true]);
    $this->get('/webmcp/exec/embed/manifest')->assertJsonPath('structuredContent', true);
});
