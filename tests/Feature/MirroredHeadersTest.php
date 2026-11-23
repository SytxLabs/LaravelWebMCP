<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Support\MirroredParameters;
use SytxLabs\LaravelWebMcp\Attributes\WebMcp as WebMcpAttribute;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Manifest\ManifestPayload;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;

#[Description('Lists the orders of a region.')]
#[WebMcpAttribute(mode: WebMcpMode::Bridge)]
class RegionOrdersTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return ['region' => $schema->string()->required(), 'limit' => $schema->integer()];
    }

    /** laravel/mcp has no builder method for the annotation yet; it reads it from the serialized schema. */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['inputSchema']['properties']['region']['x-mcp-header'] = 'Region';
        $data['inputSchema']['properties']['limit']['x-mcp-header'] = 'Limit';

        return $data;
    }

    public function handle(Request $request): Response
    {
        return Response::text('orders in '.$request->get('region'));
    }
}

class RegionServer extends Server
{
    protected array $tools = [RegionOrdersTool::class];
}

beforeEach(function () {
    if (!class_exists(MirroredParameters::class)) {
        $this->markTestSkipped('laravel/mcp < 1.2.1 has no mirrored parameters.');
    }
    WebMcp::server('region', RegionServer::class, endpoint: '/mcp/region');
    Mcp::web('/mcp/region', RegionServer::class);
});

it('tells the browser which arguments laravel/mcp expects as Mcp-Param headers', function () {
    $payload = app(ManifestPayload::class)->build(app(ServerRegistry::class)->get('region'), WebMcp::manifest('region'));

    expect($payload['tools'][0]['bridge']['headers'])->toBe([
        ['path' => ['region'], 'header' => 'Mcp-Param-Region', 'type' => 'string'],
        ['path' => ['limit'], 'header' => 'Mcp-Param-Limit', 'type' => 'integer'],
    ]);
});

it('matches what laravel/mcp checks: calls without the header fail, calls with it run', function () {
    $call = fn (array $headers) => $this->postJson('/mcp/region', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'region-orders-tool', 'arguments' => ['region' => 'eu']]], $headers);

    expect($call([])->json('error.message'))->toContain('Mcp-Param-Region');
    $call(['Mcp-Param-Region' => 'eu'])->assertOk()->assertJsonPath('result.content.0.text', 'orders in eu');
});
