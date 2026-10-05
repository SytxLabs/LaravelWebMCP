<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use PHPUnit\Framework\ExpectationFailedException;
use SytxLabs\LaravelWebMcp\Events\WebMcpToolInvoked;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;

beforeEach(function () {
    WebMcp::server('exec', Fixtures\ExecServer::class);
});

it('asserts what the current user sees', function () {
    $fake = WebMcp::fake();

    $fake->assertToolExposed('weather-tool')
        ->assertToolNotExposed('authed-tool')
        ->assertToolNotExposed('plain-tool')
        ->assertToolNotExposed('hidden-tool');

    $this->actingAs(new GenericUser(['id' => 1]));

    $fake->assertToolExposed('authed-tool', 'exec');
});

it('fails with a readable message when an exposure assertion is wrong', function () {
    $fake = WebMcp::fake();

    expect(fn () => $fake->assertToolExposed('plain-tool'))
        ->toThrow(ExpectationFailedException::class, 'WebMCP tool [plain-tool] is exposed');
    expect(fn () => $fake->assertToolNotExposed('weather-tool'))
        ->toThrow(ExpectationFailedException::class, 'is not exposed');
});

it('records invocations and asserts them', function () {
    $fake = WebMcp::fake();

    $this->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => ['city' => 'Rome']])->assertOk();
    $this->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => ['city' => 'Oslo']])->assertOk();

    $fake->assertToolInvoked('weather-tool')
        ->assertToolInvokedTimes('weather-tool', 2)
        ->assertToolNotInvoked('delete-record-tool')
        ->assertToolInvoked('weather-tool', fn (WebMcpToolInvoked $e) => $e->server === 'exec' && $e->mode === 'session');

    expect($fake->invocations())->toHaveCount(2);
});

it('fails readable when invocation assertions are wrong', function () {
    $fake = WebMcp::fake();
    $fake->assertNothingInvoked();

    $this->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => ['city' => 'Rome']])->assertOk();

    expect(fn () => $fake->assertNothingInvoked())->toThrow(ExpectationFailedException::class);
    expect(fn () => $fake->assertToolNotInvoked('weather-tool'))->toThrow(ExpectationFailedException::class, 'was not invoked');
    expect(fn () => $fake->assertToolInvoked('streaming-tool'))->toThrow(ExpectationFailedException::class, 'was invoked');
    expect(fn () => $fake->assertToolInvoked('weather-tool', fn () => false))->toThrow(ExpectationFailedException::class, 'with the given condition');
    expect(fn () => $fake->assertToolInvokedTimes('weather-tool', 3))->toThrow(ExpectationFailedException::class);
});

it('can answer a tool without running it', function () {
    $fake = WebMcp::fake()->respondWith('delete-record-tool', 'Pretended.');

    $this->postJson('/webmcp/exec/tools/delete-record-tool', ['arguments' => (object) []])
        ->assertOk()
        ->assertExactJson(['content' => [['type' => 'text', 'text' => 'Pretended.']]]);

    $fake->assertToolInvoked('delete-record-tool');
});

it('can stub an error result', function () {
    WebMcp::fake()->respondWith('weather-tool', ['content' => [['type' => 'text', 'text' => 'Nope']], 'isError' => true]);

    $this->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => ['city' => 'x']])
        ->assertOk()
        ->assertJson(['isError' => true]);
});

it('does not stub anything without a fake', function () {
    $this->postJson('/webmcp/exec/tools/weather-tool', ['arguments' => ['city' => 'Rome']])
        ->assertJson(['content' => [['text' => 'sunny in Rome']]]);
});

it('keeps stubs away from tools the user cannot see', function () {
    WebMcp::fake()->respondWith('hidden-tool', 'stubbed');

    $this->postJson('/webmcp/exec/tools/hidden-tool', ['arguments' => (object) []])->assertNotFound();
});
