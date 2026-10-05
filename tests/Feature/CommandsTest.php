<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;

/**
 * Runs a command and returns [exit code, complete output]. artisan()->expectsOutput only sees plain lines,
 * not tables or bullet lists.
 *
 * @return array{0: int, 1: string}
 */
function runCommand(string $command, array $parameters = []): array
{
    $code = Artisan::call($command, $parameters);

    return [$code, Artisan::output()];
}

beforeEach(function () {
    Auth::provider('array-users', fn () => new Fixtures\ArrayUserProvider());
    config(['auth.providers.users.driver' => 'array-users']);
    Auth::forgetGuards();
});

// ---- webmcp:list -----------------------------------------------------------------------------------

it('lists exposed tools with mode and source and the reason for every exclusion', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    [$code, $out] = runCommand('webmcp:list');

    expect($code)->toBe(0)
        ->and($out)->toContain('weather-tool')
        ->and($out)->toContain('exposed')
        ->and($out)->toContain('excluded: missing-attribute')
        ->and($out)->toContain('excluded: should-register')
        ->and($out)->toContain('excluded: app-only')
        ->and($out)->toContain('excluded: app-resource')
        ->and($out)->toContain('excluded: prompt')
        ->and($out)->toContain('excluded: template-without-authorization')
        ->and($out)->toContain(Fixtures\WeatherTool::class)
        ->and($out)->toContain('exposed (confirm)');
});

it('can hide exclusions', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    [$code, $out] = runCommand('webmcp:list', ['--exposed' => true]);

    expect($code)->toBe(0)->and($out)->toContain('weather-tool')->and($out)->not->toContain('excluded');
});

it('lists one server by slug or class', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);
    WebMcp::server('exec', Fixtures\ExecServer::class);

    [, $bySlug] = runCommand('webmcp:list', ['server' => 'basic', '--exposed' => true]);
    [$code, $byClass] = runCommand('webmcp:list', ['server' => Fixtures\ExecServer::class, '--exposed' => true]);

    expect($bySlug)->not->toContain('streaming-tool')
        ->and($code)->toBe(0)
        ->and($byClass)->toContain('streaming-tool');
});

it('lists servers that share tool names without calling it a collision', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);
    WebMcp::server('exec', Fixtures\ExecServer::class); // both expose weather-tool; no single page renders both

    [$code, $out] = runCommand('webmcp:list', ['--exposed' => true]);

    expect($code)->toBe(0)->and($out)->not->toContain('ERROR');
});

it('evaluates shouldRegister as the given user', function () {
    WebMcp::server('exec', Fixtures\ExecServer::class);

    [, $guest] = runCommand('webmcp:list', ['server' => 'exec', '--exposed' => true]);

    Auth::forgetGuards();

    [$code, $asUser] = runCommand('webmcp:list', ['server' => 'exec', '--exposed' => true, '--user' => '1']);

    expect($guest)->not->toContain('authed-tool')
        ->and($code)->toBe(0)
        ->and($asUser)->toContain('authed-tool');
});

it('fails for an unknown user and an unknown server', function () {
    WebMcp::server('exec', Fixtures\ExecServer::class);

    [$userCode, $userOut] = runCommand('webmcp:list', ['--user' => '999']);
    [$serverCode, $serverOut] = runCommand('webmcp:list', ['server' => 'nope']);

    expect($userCode)->toBe(1)->and($userOut)->toContain('No user [999]')
        ->and($serverCode)->toBe(1)->and($serverOut)->toContain('not registered');
});

it('prints JSON', function () {
    WebMcp::server('exec', Fixtures\ExecServer::class);

    [, $out] = runCommand('webmcp:list', ['--json' => true, '--exposed' => true]);
    $rows = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

    expect(collect($rows)->firstWhere('tool', 'weather-tool'))->toMatchArray(['server' => 'exec', 'kind' => 'tool', 'mode' => 'session'])
        ->and(collect($rows)->firstWhere('tool', 'delete-record-tool')['status'])->toBe('exposed (confirm)');
});

it('reports configuration errors in the list instead of crashing', function () {
    WebMcp::server('bad', Fixtures\BadPromptServer::class);

    [$code, $out] = runCommand('webmcp:list');

    expect($code)->toBe(1)->and($out)->toContain('ERROR')->and($out)->toContain('has no counterpart');
});

it('warns when nothing is registered', function () {
    [$code, $out] = runCommand('webmcp:list');

    expect($code)->toBe(0)->and($out)->toContain('No WebMCP servers are registered');
});

// ---- webmcp:check ----------------------------------------------------------------------------------

it('passes a valid configuration', function () {
    WebMcp::server('exec', Fixtures\ExecServer::class, endpoint: '/mcp/exec');

    [$code, $out] = runCommand('webmcp:check');

    expect($code)->toBe(0)->and($out)->toContain('[exec]')->and($out)->toContain('0 error(s)');
});

it('fails on configuration errors (#[WebMcp] on a prompt)', function () {
    WebMcp::server('bad', Fixtures\BadPromptServer::class);

    [$code, $out] = runCommand('webmcp:check');

    expect($code)->toBe(1)->and($out)->toContain('has no counterpart')->and($out)->toContain('1 error(s)');
});

it('fails on invalid tool names', function () {
    WebMcp::server('invalid', Fixtures\InvalidNameServer::class);

    [$code, $out] = runCommand('webmcp:check');

    expect($code)->toBe(1)->and($out)->toContain('is invalid');
});

it('fails when Bridge tools have no endpoint', function () {
    WebMcp::server('exec', Fixtures\ExecServer::class); // has Bridge-mode tools, no endpoint registered

    [$code, $out] = runCommand('webmcp:check');

    expect($code)->toBe(1)->and($out)->toContain("no 'endpoint'");
});

it('warns when servers cannot share a page, without failing', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class, endpoint: '/mcp/basic');
    WebMcp::server('weather', Fixtures\WeatherOnlyServer::class);

    [$code, $out] = runCommand('webmcp:check');

    expect($code)->toBe(0)->and($out)->toContain('Rendering all servers on ONE page would fail');
});

it('warns about risky settings without failing', function () {
    config(['webmcp.resources.generic_reader' => true, 'webmcp.resources.require_authorization' => false, 'webmcp.bridge.enforce' => 'never']);
    WebMcp::server('all', Fixtures\ExposeAllServer::class);
    WebMcp::server('exec', Fixtures\ExecServer::class, endpoint: '/mcp/exec');

    [$code, $out] = runCommand('webmcp:check');

    expect($code)->toBe(0)
        ->and($out)->toContain('exposeAll')
        ->and($out)->toContain('generic read-resource tool is enabled')
        ->and($out)->toContain('without AuthorizesWebMcpRead')
        ->and($out)->toContain('Bridge guard is disabled');
});

it('says so when nothing is registered', function () {
    [$code, $out] = runCommand('webmcp:check');

    expect($code)->toBe(0)->and($out)->toContain('No WebMCP servers are registered');
});

// ---- webmcp:install --------------------------------------------------------------------------------

it('publishes config and assets and prints the next steps', function () {
    $assets = public_path('vendor/webmcp');
    $config = config_path('webmcp.php');
    File::delete($config);

    try {
        // --force: published assets are identical copies of the sources, so overwriting what a developer
        // published earlier (e.g. `testbench workbench:build`) is harmless.
        [$code, $out] = runCommand('webmcp:install', ['--force' => true]);

        expect($code)->toBe(0)
            ->and($out)->toContain('Published config/webmcp.php')
            ->and($out)->toContain('Permissions-Policy: tools=()')
            ->and($out)->toContain('allow="tools"')
            ->and($out)->toContain('EnforceWebMcpExposure')
            ->and(File::exists($config))->toBeTrue();

        foreach (['webmcp.js', 'webmcp-core.js', 'webmcp-livewire.js', 'webmcp-alpine.js', 'webmcp-forms.js', 'webmcp.d.ts'] as $file) {
            expect(File::get("{$assets}/{$file}"))->toBe(File::get(__DIR__."/../../resources/js/{$file}"));
        }

        expect(File::get("{$assets}/webmcp-forms.css"))->toBe(File::get(__DIR__.'/../../resources/css/webmcp-forms.css'));
    } finally {
        File::delete($config); // a published config would override the package defaults in later tests
    }
});
