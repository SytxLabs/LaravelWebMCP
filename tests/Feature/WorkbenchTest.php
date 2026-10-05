<?php

declare(strict_types=1);

use Workbench\App\Providers\WorkbenchServiceProvider;

// Smoke test of the example app in workbench/: everything the README promises, wired together.

beforeEach(function () {
    $this->app->register(WorkbenchServiceProvider::class);
});

function embeddedShop(string $html): array
{
    preg_match('#<script type="application/json" id="webmcp-manifest-shop"[^>]*>(.*?)</script>#s', $html, $match);

    return json_decode($match[1] ?? 'null', true, flags: JSON_THROW_ON_ERROR);
}

it('renders the start page with the shop manifest, the declarative form and the Livewire actions', function () {
    $html = $this->get('/')->assertOk()->getContent();

    $names = array_column(embeddedShop($html)['tools'], 'name');

    expect($names)->toBe(['search-products-tool', 'read-catalog-resource', 'read-product-resource'])
        ->and($html)->toContain('toolname="search-products-tool"')
        ->and($html)->toContain('toolautosubmit')
        ->and($html)->toContain('data-webmcp-livewire')
        ->and($html)->toContain('cart.list')
        ->and($html)->not->toContain('place-order-tool');
});

it('offers the consequential order tool only to the logged-in demo user', function () {
    $html = $this->withSession(['demo_user' => 1])->get('/')->assertOk()->getContent();

    $tools = collect(embeddedShop($html)['tools'])->keyBy('name');

    expect($tools)->toHaveKey('place-order-tool')
        ->and($tools['place-order-tool']['confirm'])->toBeTrue()
        ->and($tools['place-order-tool']['annotations'])->toBe(['consequentialHint' => true]);
});

it('runs the search tool through the Session route', function () {
    $this->postJson('/webmcp/shop/tools/search-products-tool', ['arguments' => ['query' => 'hat', 'in_stock' => true]])
        ->assertOk()
        ->assertJsonPath('content.0.type', 'text');

    $text = $this->postJson('/webmcp/shop/tools/search-products-tool', ['arguments' => ['query' => 'hat']])->json('content.0.text');

    expect(json_decode($text, true)['count'])->toBe(2);
});

it('reads a product through the template resource and refuses traversal', function () {
    $ok = $this->postJson('/webmcp/shop/resources/read-product-resource', ['arguments' => ['productId' => '3']]);

    expect($ok->json('content.0.text'))->toContain('Wool Hat');

    $this->postJson('/webmcp/shop/resources/read-product-resource', ['arguments' => ['productId' => '../3']])
        ->assertOk()->assertJson(['isError' => true]);

    $this->postJson('/webmcp/shop/resources/read-product-resource', ['arguments' => ['productId' => '99']])
        ->assertForbidden(); // AuthorizesWebMcpRead: unknown product
});

it('serves the Bridge-mode catalog resource through the guarded MCP endpoint', function () {
    $response = $this->postJson('/mcp/shop', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => ['uri' => 'shop://catalog'],
    ], ['X-WebMCP' => '1']);

    $response->assertOk();
    expect($response->json('result.contents.0.text'))->toContain('Trail Runner');

    // not exposed to browser callers: anything else
    $this->postJson('/mcp/shop', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'search-products-tool', 'arguments' => (object) []]], ['X-WebMCP' => '1'])
        ->assertForbidden();
});

it('refuses the order tool for guests even when called directly', function () {
    $this->postJson('/webmcp/shop/tools/place-order-tool', ['arguments' => ['product_id' => 1]])->assertNotFound();
});

it('validates the webmcp configuration of the workbench', function () {
    $this->artisan('webmcp:check')->assertExitCode(0);
});
