<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Testing\TestResponse;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpArgumentsException;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Manifest\ToolDefinition;
use SytxLabs\LaravelWebMcp\Support\ResourceVariables;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;

beforeEach(function () {
    WebMcp::server('exec', Fixtures\ExecServer::class);
});

function callTool(string $tool, array $arguments = [], array $extra = []): TestResponse
{
    return test()->postJson("/webmcp/exec/tools/{$tool}", ['arguments' => (object) $arguments, ...$extra]);
}

function readResource(string $resource, array $arguments = []): TestResponse
{
    return test()->postJson("/webmcp/exec/resources/{$resource}", ['arguments' => (object) $arguments]);
}

function text(TestResponse $response): string
{
    return implode("\n", array_column($response->json('content') ?? [], 'text'));
}

// ---- tools -----------------------------------------------------------------

it('executes a tool in-process and returns the spec result shape', function () {
    $response = callTool('weather-tool', ['city' => 'Berlin']);

    $response->assertOk()
        ->assertJson(['content' => [['type' => 'text', 'text' => 'sunny in Berlin']]])
        ->assertJsonMissingPath('isError');

    // Older Symfony versions add max-age/no-cache around it; what matters is that nothing is stored.
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('turns a ValidationException into a compact agent-readable error result, not a 422', function () {
    $response = callTool('weather-tool', []);

    $response->assertOk()->assertJson(['isError' => true]);
    expect(text($response))->toBe('The city field is required.')
        ->and($response->getContent())->not->toContain('<html');
});

it('maps Response::error to isError', function () {
    callTool('erroring-tool')->assertOk()->assertJson([
        'isError' => true,
        'content' => [['type' => 'text', 'text' => 'Cannot do that.']],
    ]);
});

it('does not leak exception messages outside debug mode', function () {
    config(['app.debug' => false]);

    $response = callTool('failing-tool');

    $response->assertOk()->assertJson(['isError' => true]);
    expect(text($response))->toBe('An internal server error occurred.')
        ->and($response->getContent())->not->toContain('secret internal detail');
});

it('collects generator responses into one result and drops notifications', function () {
    $response = callTool('streaming-tool');

    $response->assertOk();
    expect($response->json('content'))->toBe([
        ['type' => 'text', 'text' => 'part one'],
        ['type' => 'text', 'text' => 'part two'],
    ]);
});

it('returns structured content as JSON text and adds structuredContent only behind the flag', function () {
    $plain = callTool('structured-tool');

    expect(text($plain))->toBe('{"temp":21,"unit":"C"}')
        ->and($plain->json())->not->toHaveKey('structuredContent');

    config(['webmcp.features.structured_content' => true]);

    expect(callTool('structured-tool')->json('structuredContent'))->toBe(['temp' => 21, 'unit' => 'C']);
});

it('replaces binary tool output by a text fallback (multimodal is not in the spec)', function () {
    $response = callTool('image-tool');

    expect(text($response))->toStartWith('[image content omitted: image/png');
});

it('runs tools as the session user', function () {
    expect(text(callTool('who-am-i-tool')))->toBe('user:guest');

    $this->actingAs(new GenericUser(['id' => 7]));

    expect(text(callTool('who-am-i-tool')))->toBe('user:7');
});

it('re-evaluates shouldRegister on every call, not only for the manifest', function () {
    callTool('authed-tool')->assertNotFound();

    $this->actingAs(new GenericUser(['id' => 1]));

    expect(text(callTool('authed-tool')))->toBe('authed');
});

it('returns 404 for unknown tools, non-exposed tools and tools of another mode', function () {
    callTool('does-not-exist')->assertNotFound();
    callTool('hidden-tool')->assertNotFound();
    callTool('custom.name')->assertNotFound();   // Bridge-mode tool is not served by Session routes
    $this->postJson('/webmcp/nope/tools/weather-tool', [])->assertNotFound();
});

it('does not serve resource tools on the tool route and vice versa', function () {
    callTool('read-settings-resource')->assertNotFound();
    readResource('weather-tool')->assertNotFound();
});

it('rejects arguments that are not a JSON object', function () {
    $this->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => ['a', 'b']])->assertStatus(400);
});

// ---- resources -------------------------------------------------------------

it('reads a static resource', function () {
    readResource('read-settings-resource')->assertOk()->assertJson([
        'content' => [['type' => 'text', 'text' => '{"theme":"dark"}']],
    ]);
});

it('reads a template resource with validated variables', function () {
    expect(text(readResource('read-user-doc-resource', ['userId' => '5', 'docId' => 'abc'])))->toBe('doc abc');
});

it('accepts integer template variables', function () {
    expect(text(readResource('read-user-doc-resource', ['userId' => 5, 'docId' => 9])))->toBe('doc 9');
});

it('rejects path traversal and encoded separators in template variables', function (string $value) {
    $response = readResource('read-user-doc-resource', ['userId' => '5', 'docId' => $value]);

    $response->assertOk()->assertJson(['isError' => true]);
    expect(text($response))->toBe('Invalid value for [docId].');
})->with([
    '..',
    '.',
    '../secret',
    'a/b',
    'a\\b',
    '%2e%2e',
    '%2E%2E%2F',
    '..%2Fsecret',
    '%252e%252e',          // double-encoded
    '1?admin=1',
    '1#frag',
    '1%3Fadmin',
    '1%2523frag',          // double-encoded #
    "bad\0byte",
    "line\nbreak",
    '',
]);

it('rejects unknown and missing resource arguments', function () {
    expect(text(readResource('read-user-doc-resource', ['userId' => '5', 'docId' => '1', 'extra' => 'x'])))
        ->toBe('Unknown argument [extra].')
        ->and(text(readResource('read-user-doc-resource', ['userId' => '5'])))
        ->toBe('Missing required argument [docId].');
});

it('enforces a per-resource variable pattern', function () {
    // read-user-doc-resource has no pattern; use a one-off definition through the validator
    $validator = app(ResourceVariables::class);
    $tool = new ToolDefinition(
        server: 'x',
        name: 'x',
        title: 'x',
        description: 'x',
        inputSchema: [],
        annotations: [],
        exposedTo: [],
        mode: WebMcpMode::Session,
        confirm: false,
        kind: 'resource',
        source: Fixtures\UserDocResource::class,
        mcpName: 'x',
        uriTemplate: 'file://a/{id}',
        variableNames: ['id'],
        variablePattern: '/^\d+$/',
    );

    expect($validator->validate($tool, ['id' => '42']))->toBe(['id' => '42']);
    $validator->validate($tool, ['id' => 'abc']);
})->throws(InvalidWebMcpArgumentsException::class);

it('checks authorization of the concrete read on every call', function () {
    readResource('read-denying-resource', ['id' => '1'])->assertForbidden();

    readResource('read-guarded-resource')->assertForbidden();

    $this->actingAs(new GenericUser(['id' => 1]));
    expect(text(readResource('read-guarded-resource')))->toBe('guarded content');
});

it('replaces blobs by a fallback unless configured', function () {
    expect(text(readResource('read-blob-resource')))->toStartWith('[binary content omitted: image/png');

    config(['webmcp.resources.blobs' => 'mcp-image']);

    expect(readResource('read-blob-resource')->json('content.0'))->toBe([
        'type' => 'image',
        'data' => base64_encode('binarydata'),
        'mimeType' => 'image/png',
    ]);

    config(['webmcp.resources.blobs' => 'base64-text']);

    expect(text(readResource('read-blob-resource')))->toBe('data:image/png;base64,'.base64_encode('binarydata'));
});

it('only resolves exposed resources through the generic read-resource tool', function () {
    config(['webmcp.resources.generic_reader' => true]);

    expect(text(readResource('read-resource', ['uri' => 'file://users/3/docs/9'])))->toBe('doc 9')
        ->and(text(readResource('read-resource', ['uri' => 'file://resources/settings'])))->toBe('{"theme":"dark"}');

    // not an exposed resource
    expect(text(readResource('read-resource', ['uri' => 'file://etc/passwd'])))->toBe('This URI is not available.');

    // traversal through a template
    expect(text(readResource('read-resource', ['uri' => 'file://users/3/docs/..'])))->toBe('Invalid value for [docId].');
    expect(text(readResource('read-resource', ['uri' => 'file://users/3/docs/%2e%2e'])))->toBe('Invalid value for [docId].');

    // authorization of the concrete resource still applies
    readResource('read-resource', ['uri' => 'file://resources/guarded'])->assertForbidden();
});

it('does not offer the generic reader when disabled', function () {
    readResource('read-resource', ['uri' => 'file://resources/settings'])->assertNotFound();
});

// ---- confirmation ----------------------------------------------------------

it('enforces a single-use confirmation token for consequential tools when enabled', function () {
    config(['webmcp.confirmation.server_enforced' => true]);
    $this->actingAs(new GenericUser(['id' => 1]));

    $first = callTool('delete-record-tool');
    $first->assertOk()->assertJson(['isError' => true, 'confirmation' => ['required' => true]]);
    $token = $first->json('confirmation.token');

    expect(text(callTool('delete-record-tool', [], ['confirmation' => $token])))->toBe('deleted');

    // single use
    callTool('delete-record-tool', [], ['confirmation' => $token])->assertJson(['confirmation' => ['required' => true]]);
});

it('binds the confirmation token to the arguments', function () {
    config(['webmcp.confirmation.server_enforced' => true]);
    $this->actingAs(new GenericUser(['id' => 1]));

    $token = callTool('delete-record-tool', ['id' => 1])->json('confirmation.token');

    callTool('delete-record-tool', ['id' => 2], ['confirmation' => $token])
        ->assertJson(['confirmation' => ['required' => true]]);
});

it('does not ask for confirmation on non-consequential tools', function () {
    config(['webmcp.confirmation.server_enforced' => true]);

    expect(text(callTool('weather-tool', ['city' => 'Rome'])))->toBe('sunny in Rome');
});

// ---- manifest endpoint -----------------------------------------------------

it('serves the current user manifest with a fresh CSRF token', function () {
    $guest = $this->getJson('/webmcp/exec/manifest');

    $guest->assertOk()->assertJsonStructure(['version', 'server', 'tools', 'csrf', 'locale', 'endpoints' => ['manifest', 'tools', 'resources']]);
    expect(array_column($guest->json('tools'), 'name'))->not->toContain('authed-tool');

    $this->actingAs(new GenericUser(['id' => 1]));

    expect(array_column($this->getJson('/webmcp/exec/manifest')->json('tools'), 'name'))->toContain('authed-tool');
});

it('returns 404 for the manifest of an unknown server', function () {
    $this->getJson('/webmcp/nope/manifest')->assertNotFound();
});

// ---- request hardening -------------------------------------------------------

it('requires JSON', function () {
    $this->post('/webmcp/exec/tools/weather-tool', ['arguments' => []], ['Content-Type' => 'text/plain'])->assertStatus(415);
});

it('rejects cross-origin and cross-site requests', function () {
    $this->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => (object) ['city' => 'x']], ['Origin' => 'https://evil.example'])
        ->assertForbidden();

    $this->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => (object) ['city' => 'x']], ['Sec-Fetch-Site' => 'cross-site'])
        ->assertForbidden();

    $this->getJson('/webmcp/exec/manifest', ['Sec-Fetch-Site' => 'same-site'])->assertForbidden();
});

it('accepts same-origin requests and configured request origins', function () {
    $own = rtrim(config('app.url'), '/');

    $this->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => (object) ['city' => 'x']], ['Origin' => $own, 'Sec-Fetch-Site' => 'same-origin'])
        ->assertOk();

    config(['webmcp.request_origins' => ['https://spa.example.com']]);

    $this->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => (object) ['city' => 'x']], ['Origin' => 'https://spa.example.com'])
        ->assertOk();
});

it('rejects oversized bodies', function () {
    config(['webmcp.limits.max_request_bytes' => 1024]);

    $this->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => (object) ['city' => str_repeat('a', 5000)]])
        ->assertStatus(413);
});

it('enforces CSRF protection on POST routes outside of testing', function () {
    $this->app['env'] = 'production';

    $this->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => (object) ['city' => 'x']])->assertStatus(419);
});

it('rate limits calls per user or IP', function () {
    config(['webmcp.rate_limit.per_minute' => 2]);

    callTool('weather-tool', ['city' => 'a'])->assertOk();
    callTool('weather-tool', ['city' => 'b'])->assertOk();
    callTool('weather-tool', ['city' => 'c'])->assertStatus(429);
});

// ---- request guard edge cases ---------------------------------------------

it('rejects cross-site and top-level navigation style requests', function (string $site) {
    callTool('weather-tool', ['city' => 'Berlin'], [])->assertOk();

    test()->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => (object) ['city' => 'Berlin']], ['Sec-Fetch-Site' => $site])
        ->assertForbidden();
})->with(['cross-site', 'same-site', 'none']);

it('rejects requests with a mismatching or opaque origin', function (string $origin) {
    test()->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => (object) ['city' => 'Berlin']], ['Origin' => $origin])
        ->assertForbidden();
})->with(['https://evil.example', 'null', 'garbage']);

it('rejects non-json bodies and bodies over the declared limit', function () {
    test()->call('POST', '/webmcp/exec/tools/weather-tool', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'x')->assertStatus(415);
    test()->call('POST', '/webmcp/exec/tools/weather-tool', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_CONTENT_LENGTH' => '999999'], '{}')->assertStatus(413);
});

it('rejects malformed arguments for the generic resource reader', function () {
    config(['webmcp.resources.generic_reader' => true, 'webmcp.resources.require_authorization' => false]);

    readResource('read-resource', ['uri' => 'file://resources/bridge', 'extra' => 1])->assertOk()->assertJson(['isError' => true]);
    readResource('read-resource', ['uri' => 5])->assertOk()->assertJson(['isError' => true]);
});
