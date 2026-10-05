<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Http\Middleware\EnforceWebMcpExposure;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;

beforeEach(function () {
    WebMcp::server('exec', Fixtures\ExecServer::class, endpoint: '/mcp/exec');

    Mcp::web('/mcp/exec', Fixtures\ExecServer::class)
        ->middleware(EnforceWebMcpExposure::class.':exec');
});

function rpc(string $method, array $params = [], array $headers = []): TestResponse
{
    return test()->postJson('/mcp/exec', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => (object) $params,
    ], $headers);
}

function callBridge(string $name, array $arguments = [], array $headers = ['X-WebMCP' => '1']): TestResponse
{
    return rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $headers);
}

it('lets the Bridge call an opted-in, Bridge-mode tool through the real Mcp::web() endpoint', function () {
    $response = callBridge('renamed-tool');

    $response->assertOk();
    expect($response->json('result.content.0.text'))->toBe('renamed');
});

it('blocks browser calls to tools that are not exposed in Bridge mode', function (string $name) {
    callBridge($name)->assertForbidden()->assertJsonPath('error.code', -32003);
})->with([
    'session-mode tool' => 'weather-tool',
    'tool without attribute' => 'plain-tool',
    'tool that failed shouldRegister' => 'authed-tool',
    'unknown tool' => 'nope',
]);

it('does not touch plain MCP clients without marker header or session cookie', function () {
    // This is the pre-existing MCP endpoint behaviour: every tool of the server is reachable.
    $response = callBridge('plain-tool', headers: []);

    $response->assertOk();
    expect($response->json('result.content.0.text'))->toBe('plain');
});

it('treats a session cookie as browser-style even without the marker header', function () {
    $response = $this->withCredentials()->withCookie(config('session.cookie'), 'abc')->postJson('/mcp/exec', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'plain-tool', 'arguments' => (object) []],
    ]);

    $response->assertForbidden();
});

it('can guard every caller or switch the guard off', function () {
    config(['webmcp.bridge.enforce' => 'always']);
    callBridge('plain-tool', headers: [])->assertForbidden();

    config(['webmcp.bridge.enforce' => 'never']);
    callBridge('plain-tool')->assertOk();
});

it('only allows tools/call, resources/read, ping and initialize for browser requests', function (string $method) {
    rpc($method, [], ['X-WebMCP' => '1'])->assertForbidden();
})->with(['tools/list', 'resources/list', 'resources/templates/list', 'prompts/list', 'prompts/get', 'completion/complete', 'server/discover', 'subscriptions/listen']);

it('rejects batches and invalid bodies from browser requests', function () {
    $this->postJson('/mcp/exec', [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']], ['X-WebMCP' => '1'])->assertStatus(400);
});

it('allows only exposed Bridge resources through resources/read', function () {
    $ok = rpc('resources/read', ['uri' => 'file://resources/bridge'], ['X-WebMCP' => '1']);

    $ok->assertOk();
    expect($ok->json('result.contents.0.text'))->toBe('bridge resource');

    // Session-mode resource and an unexposed URI: both unavailable over the Bridge
    rpc('resources/read', ['uri' => 'file://resources/settings'], ['X-WebMCP' => '1'])->assertStatus(400);
    rpc('resources/read', ['uri' => 'file://etc/passwd'], ['X-WebMCP' => '1'])->assertStatus(400);
    rpc('resources/read', [], ['X-WebMCP' => '1'])->assertStatus(400);
});

it('enforces the confirmation token over the Bridge through a header', function () {
    config(['webmcp.confirmation.server_enforced' => true]);
    $this->actingAs(new GenericUser(['id' => 1]));

    $first = callBridge('bridge-delete-tool');
    $first->assertStatus(428)->assertJsonPath('error.data.confirmation.required', true);
    $token = $first->json('error.data.confirmation.token');

    $second = callBridge('bridge-delete-tool', headers: ['X-WebMCP' => '1', 'X-WebMCP-Confirmation' => $token]);

    $second->assertOk();
    expect($second->json('result.content.0.text'))->toBe('bridge deleted');

    callBridge('bridge-delete-tool', headers: ['X-WebMCP' => '1', 'X-WebMCP-Confirmation' => $token])->assertStatus(428);
});

it('lets ping through', function () {
    rpc('ping', [], ['X-WebMCP' => '1'])->assertOk();
});
