<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpTargetException;
use SytxLabs\LaravelWebMcp\Exceptions\WebMcpNameCollisionException;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Manifest\ExclusionReason;
use SytxLabs\LaravelWebMcp\Manifest\Manifest;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;

function reasons(Manifest $manifest): array
{
    $out = [];

    foreach ($manifest->exclusions as $exclusion) {
        $out[$exclusion->source] = $exclusion->reason;
    }

    return $out;
}

it('denies by default: only classes with their own attribute are exposed', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $manifest = WebMcp::manifest('basic');

    expect($manifest->toolNames())->not->toContain('plain-tool')
        ->and(reasons($manifest)[Fixtures\PlainTool::class])->toBe(ExclusionReason::MissingAttribute)
        ->and(reasons($manifest)[Fixtures\PlainResource::class])->toBe(ExclusionReason::MissingAttribute);
});

it('maps a laravel/mcp tool to a WebMCP tool dictionary', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $tool = WebMcp::manifest('basic')->tool('weather-tool');
    $data = $tool->toArray();

    expect($data['name'])->toBe('weather-tool')
        ->and($data['title'])->toBe('Weather Tool')
        ->and($data['description'])->toBe('Current weather for a city.')
        ->and($data['inputSchema']['type'])->toBe('object')
        ->and(array_keys((array) $data['inputSchema']['properties']))->toBe(['city', 'units'])
        ->and($data['inputSchema']['required'])->toBe(['city'])
        ->and($data['annotations'])->toBe(['readOnlyHint' => true])   // IsIdempotent has no WebMCP counterpart
        ->and($data['mode'])->toBe('session')
        ->and($data['confirm'])->toBeFalse();
});

it('serializes empty input schemas as objects, never as lists', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $json = json_encode(WebMcp::manifest('basic')->tool('delete-record-tool')->toArray()['inputSchema']);

    expect($json)->toBe('{"type":"object","properties":{},"required":[]}');
});

it('maps IsDestructive to consequentialHint and enables confirm', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $tool = WebMcp::manifest('basic')->tool('delete-record-tool');

    expect($tool->annotations)->toBe(['consequentialHint' => true])
        ->and($tool->confirm)->toBeTrue();
});

it('applies name override, mode and untrusted hint from the class attribute', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $tool = WebMcp::manifest('basic')->tool('custom.name');

    expect($tool->mode)->toBe(WebMcpMode::Bridge)
        ->and($tool->annotations)->toBe(['untrustedContentHint' => true])
        ->and($tool->mcpName)->toBe('renamed-tool');
});

it('evaluates shouldRegister with the current request', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $manifest = WebMcp::manifest('basic');

    expect($manifest->tool('hidden-tool'))->toBeNull()
        ->and(reasons($manifest)[Fixtures\HiddenTool::class])->toBe(ExclusionReason::ShouldRegister);
});

it('excludes app-only tools unless allowAppOnly is set, and keeps model-visible app tools', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $manifest = WebMcp::manifest('basic');

    expect($manifest->tool('app-only-tool'))->toBeNull()
        ->and(reasons($manifest)[Fixtures\AppOnlyTool::class])->toBe(ExclusionReason::AppOnly)
        ->and($manifest->tool('allowed-app-only-tool'))->not->toBeNull()
        ->and($manifest->tool('model-app-tool'))->not->toBeNull();
});

it('never exposes AppResources', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $manifest = WebMcp::manifest('basic');

    expect(reasons($manifest)[Fixtures\DashboardApp::class])->toBe(ExclusionReason::AppResource)
        ->and($manifest->tool('read-dashboard-app'))->toBeNull();
});

it('lists prompts as excluded and fails loudly when a prompt carries the attribute', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);
    WebMcp::server('badprompt', Fixtures\BadPromptServer::class);

    expect(reasons(WebMcp::manifest('basic'))[Fixtures\HelloPrompt::class])->toBe(ExclusionReason::Prompt);

    WebMcp::manifest('badprompt');
})->throws(InvalidWebMcpTargetException::class, 'has no counterpart');

it('exposes ToolSearch catalog members individually and never the search/execute meta tools', function () {
    WebMcp::server('catalog', Fixtures\CatalogServer::class);

    $manifest = WebMcp::manifest('catalog');

    expect($manifest->toolNames())->toContain('catalog-tool', 'weather-tool')
        ->and($manifest->toolNames())->not->toContain('search_tools', 'execute_tools', 'catalog-plain-tool')
        ->and(reasons($manifest)[Fixtures\CatalogPlainTool::class])->toBe(ExclusionReason::MissingAttribute);
});

it('uses server attribute defaults but only for classes with their own attribute', function () {
    WebMcp::server('defaults', Fixtures\ServerDefaultsServer::class);

    $manifest = WebMcp::manifest('defaults');

    // InheritingTool: mode and confirm come from the server attribute
    expect($manifest->tool('inheriting-tool')->mode)->toBe(WebMcpMode::Bridge)
        ->and($manifest->tool('inheriting-tool')->confirm)->toBeTrue()
        // RenamedTool sets its own mode: class attribute wins
        ->and($manifest->tool('custom.name')->mode)->toBe(WebMcpMode::Bridge)
        // No own attribute: still not exposed
        ->and($manifest->tool('plain-tool'))->toBeNull();
});

it('lets the registration mode apply below the attributes and above the config default', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class, mode: 'bridge');

    $manifest = WebMcp::manifest('basic');

    expect($manifest->tool('delete-record-tool')->mode)->toBe(WebMcpMode::Bridge)   // no mode on class -> registration
        ->and($manifest->tool('weather-tool')->mode)->toBe(WebMcpMode::Session);    // class attribute wins
});

it('exposes everything of a server with exposeAll, except prompts and app resources', function () {
    WebMcp::server('all', Fixtures\ExposeAllServer::class);

    $manifest = WebMcp::manifest('all');

    expect($manifest->toolNames())->toContain('plain-tool', 'weather-tool', 'read-plain-resource')
        ->and($manifest->tool('read-dashboard-app'))->toBeNull()
        ->and(reasons($manifest)[Fixtures\DashboardApp::class])->toBe(ExclusionReason::AppResource)
        ->and(reasons($manifest)[Fixtures\HelloPrompt::class])->toBe(ExclusionReason::Prompt);
});

it('applies the server name prefix', function () {
    WebMcp::server('shop', Fixtures\PrefixedServer::class);

    expect(WebMcp::manifest('shop')->toolNames())->toBe(['shop_weather-tool']);
});

it('returns nothing when WebMCP is disabled', function () {
    config(['webmcp.enabled' => false]);
    WebMcp::server('basic', Fixtures\BasicServer::class);

    expect(WebMcp::manifest('basic')->tools)->toBe([]);
});

it('only allows exposedTo origins from the config allowlist', function () {
    WebMcp::server('origin', Fixtures\OriginServer::class);
    WebMcp::server('unlisted', Fixtures\UnlistedOriginServer::class);

    expect(WebMcp::manifest('origin')->tool('exposed-to-tool')->exposedTo)->toBe(['https://chat.example.com']);

    WebMcp::manifest('unlisted');
})->throws(InvalidWebMcpConfigurationException::class, 'not listed in config');

it('rejects invalid WebMCP tool names', function () {
    WebMcp::server('bad', Fixtures\InvalidNameServer::class);

    WebMcp::manifest('bad');
})->throws(InvalidWebMcpConfigurationException::class, 'is invalid');

it('detects name collisions between tools of different servers on one page', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);
    WebMcp::server('weather', Fixtures\WeatherOnlyServer::class);

    WebMcp::manifests();
})->throws(WebMcpNameCollisionException::class, 'weather-tool');

it('detects collisions between a tool and a resource tool', function () {
    WebMcp::server('clash', Fixtures\ResourceCollisionServer::class);

    WebMcp::manifest('clash');
})->throws(WebMcpNameCollisionException::class, 'get-weather');

it('drops tools above the per-server limit and records them', function () {
    config(['webmcp.limits.max_tools' => 2]);
    Log::spy();
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $manifest = WebMcp::manifest('basic');

    expect($manifest->tools)->toHaveCount(2);

    $limited = array_filter($manifest->exclusions, fn ($e) => $e->reason === ExclusionReason::Limit);
    expect($limited)->not->toBeEmpty();

    Log::shouldHaveReceived('warning')->once();
});

it('never leaks class names, paths or internal fields into the browser manifest', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $json = json_encode(WebMcp::manifest('basic')->toArray());

    expect($json)->not->toContain('SytxLabs')
        ->and($json)->not->toContain('Fixtures')
        ->and($json)->not->toContain('.php')
        ->and($json)->not->toContain('"mcpName"')
        ->and($json)->not->toContain('"source"');
});

it('adds outputSchema only behind the feature flag', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    expect(WebMcp::manifest('basic')->tool('weather-tool')->inputSchema)->not->toHaveKey('outputSchema');
});
