<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use Monolog\Handler\TestHandler;
use SytxLabs\LaravelWebMcp\Events\WebMcpManifestBuilt;
use SytxLabs\LaravelWebMcp\Events\WebMcpToolFailed;
use SytxLabs\LaravelWebMcp\Events\WebMcpToolInvoked;
use SytxLabs\LaravelWebMcp\Events\WebMcpToolSucceeded;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Http\Middleware\EnforceWebMcpExposure;
use SytxLabs\LaravelWebMcp\Observability\ArgumentMasker;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;

beforeEach(function () {
    WebMcp::server('exec', Fixtures\ExecServer::class, endpoint: '/mcp/exec');
});

function call(string $tool, array $arguments = [], array $extra = []): TestResponse
{
    return test()->postJson("/webmcp/exec/tools/{$tool}", ['arguments' => (object) $arguments, ...$extra]);
}

function read(string $resource, array $arguments = []): TestResponse
{
    return test()->postJson("/webmcp/exec/resources/{$resource}", ['arguments' => (object) $arguments]);
}

// ---- events -------------------------------------------------------------------------------------

it('dispatches Invoked and Succeeded with user, server, tool, mode and duration', function () {
    Event::fake([WebMcpToolInvoked::class, WebMcpToolSucceeded::class, WebMcpToolFailed::class]);
    $this->actingAs(new GenericUser(['id' => 9]));

    call('weather-tool', ['city' => 'Rome'])->assertOk();

    Event::assertDispatched(WebMcpToolInvoked::class, fn (WebMcpToolInvoked $e) => $e->tool === 'weather-tool'
        && $e->server === 'exec'
        && $e->mode === 'session'
        && $e->kind === 'tool'
        && $e->consequential === false
        && $e->user?->getAuthIdentifier() === 9
        && $e->arguments === null);

    Event::assertDispatched(WebMcpToolSucceeded::class, fn (WebMcpToolSucceeded $e) => $e->tool === 'weather-tool' && $e->durationMs > 0);
    Event::assertNotDispatched(WebMcpToolFailed::class);
});

it('dispatches Failed with a reason for tool errors', function () {
    Event::fake([WebMcpToolInvoked::class, WebMcpToolSucceeded::class, WebMcpToolFailed::class]);

    call('erroring-tool')->assertOk();
    call('weather-tool')->assertOk(); // missing argument: validation error result

    Event::assertDispatchedTimes(WebMcpToolFailed::class, 2);
    Event::assertDispatched(WebMcpToolFailed::class, fn (WebMcpToolFailed $e) => $e->tool === 'erroring-tool' && $e->reason === 'tool-error');
    Event::assertNotDispatched(WebMcpToolSucceeded::class);
});

it('reports invalid arguments, forbidden reads and confirmation challenges as failure reasons', function () {
    Event::fake([WebMcpToolFailed::class]);
    $this->actingAs(new GenericUser(['id' => 1]));

    read('read-user-doc-resource', ['userId' => '1', 'docId' => '..'])->assertOk();
    read('read-denying-resource', ['id' => '1'])->assertForbidden();

    config(['webmcp.confirmation.server_enforced' => true]);
    call('delete-record-tool')->assertOk();

    Event::assertDispatched(WebMcpToolFailed::class, fn (WebMcpToolFailed $e) => $e->reason === 'invalid-arguments');
    Event::assertDispatched(WebMcpToolFailed::class, fn (WebMcpToolFailed $e) => $e->reason === 'forbidden' && $e->kind === 'resource');
    Event::assertDispatched(WebMcpToolFailed::class, fn (WebMcpToolFailed $e) => $e->reason === 'confirmation-required' && $e->consequential === true);
});

it('does not dispatch anything for tools the user cannot see', function () {
    Event::fake([WebMcpToolInvoked::class]);

    call('hidden-tool')->assertNotFound();

    Event::assertNotDispatched(WebMcpToolInvoked::class);
});

it('attaches masked arguments only when configured', function () {
    Event::fake([WebMcpToolInvoked::class]);
    call('weather-tool', ['city' => 'Rome'])->assertOk();
    Event::assertDispatched(WebMcpToolInvoked::class, fn (WebMcpToolInvoked $e) => $e->arguments === null);

    Event::fake([WebMcpToolInvoked::class]);
    config(['webmcp.events.include_arguments' => true]);
    call('weather-tool', ['city' => 'Rome', 'user_password' => 'hunter2', 'nested' => ['API_KEY' => 'k', 'ok' => 1]])->assertOk();

    Event::assertDispatched(WebMcpToolInvoked::class, fn (WebMcpToolInvoked $e) => $e->arguments === [
        'city' => 'Rome',
        'user_password' => '***',
        'nested' => ['API_KEY' => '***', 'ok' => 1],
    ]);
});

it('dispatches ManifestBuilt with user, server, tool count and duration', function () {
    Event::fake([WebMcpManifestBuilt::class]);
    $this->actingAs(new GenericUser(['id' => 4]));

    WebMcp::manifest('exec');

    Event::assertDispatched(WebMcpManifestBuilt::class, fn (WebMcpManifestBuilt $e) => $e->server === 'exec'
        && $e->toolCount > 5
        && $e->durationMs >= 0
        && $e->cacheHit === false
        && $e->user?->getAuthIdentifier() === 4);
});

it('masks sensitive keys at any depth, case-insensitively', function () {
    $masked = app(ArgumentMasker::class)->mask([
        'Authorization' => 'Bearer x',
        'list' => [['card_number' => '4111'], 'plain'],
        'name' => 'ok',
        'CVV' => '123',
    ]);

    expect($masked)->toBe(['Authorization' => '***', 'list' => [['card_number' => '***'], 'plain'], 'name' => 'ok', 'CVV' => '***']);
});

it('lets an application listener react to the events', function () {
    $seen = [];
    Event::listen(WebMcpToolSucceeded::class, function (WebMcpToolSucceeded $e) use (&$seen) {
        $seen[] = $e->tool;
    });

    call('weather-tool', ['city' => 'Oslo'])->assertOk();

    expect($seen)->toBe(['weather-tool']);
});

// ---- Bridge ---------------------------------------------------------------------------------------

it('records Bridge calls through the guard (tools and resources)', function () {
    Mcp::web('/mcp/exec', Fixtures\ExecServer::class)->middleware(EnforceWebMcpExposure::class.':exec');
    Event::fake([WebMcpToolInvoked::class, WebMcpToolSucceeded::class, WebMcpToolFailed::class]);

    $this->postJson('/mcp/exec', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'renamed-tool', 'arguments' => (object) []]], ['X-WebMCP' => '1'])->assertOk();
    $this->postJson('/mcp/exec', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'file://resources/bridge']], ['X-WebMCP' => '1'])->assertOk();

    Event::assertDispatched(WebMcpToolInvoked::class, fn (WebMcpToolInvoked $e) => $e->mode === 'bridge' && $e->tool === 'custom.name');
    Event::assertDispatched(WebMcpToolSucceeded::class, fn (WebMcpToolSucceeded $e) => $e->kind === 'resource' && $e->tool === 'read-bridge-resource');
    Event::assertNotDispatched(WebMcpToolFailed::class);
});

// ---- audit log ------------------------------------------------------------------------------------

function auditRecords(): array
{
    /** @var TestHandler $handler */
    $handler = Log::channel('audit')->getLogger()->getHandlers()[0];

    return $handler->getRecords();
}

beforeEach(function () {
    config([
        'logging.channels.audit' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'webmcp.audit.enabled' => true,
        'webmcp.audit.channel' => 'audit',
    ]);
});

it('audits consequential tools only by default, with user, IP, outcome and masked arguments', function () {
    $this->actingAs(new GenericUser(['id' => 3]));

    call('weather-tool', ['city' => 'Rome'])->assertOk();
    call('delete-record-tool', ['record' => 5, 'api_token' => 'secret'])->assertOk();

    $records = auditRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]['message'])->toBe('webmcp.tool')
        ->and($records[0]['context'])->toMatchArray([
            'user_id' => 3,
            'server' => 'exec',
            'tool' => 'delete-record-tool',
            'consequential' => true,
            'status' => 'success',
            'reason' => null,
            'arguments' => ['record' => 5, 'api_token' => '***'],
        ])
        ->and($records[0]['context']['ip'])->not->toBeEmpty()
        ->and($records[0]['context']['duration_ms'])->toBeGreaterThan(0);
});

it('can audit every tool and records failures with their reason', function () {
    config(['webmcp.audit.only_consequential' => false]);

    call('erroring-tool')->assertOk();

    expect(auditRecords())->toHaveCount(1)
        ->and(auditRecords()[0]['context'])->toMatchArray(['tool' => 'erroring-tool', 'status' => 'failed', 'reason' => 'tool-error']);
});

it('leaves arguments out of the audit log when configured', function () {
    config(['webmcp.audit.include_arguments' => false]);

    call('delete-record-tool', ['record' => 5])->assertOk();

    expect(auditRecords()[0]['context'])->not->toHaveKey('arguments');
});

it('writes nothing when the audit log is off', function () {
    config(['webmcp.audit.enabled' => false]);

    call('delete-record-tool')->assertOk();

    expect(auditRecords())->toBe([]);
});
