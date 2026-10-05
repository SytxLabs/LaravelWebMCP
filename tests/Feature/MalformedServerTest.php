<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Tests\Feature\Malformed;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolSearch;
use SytxLabs\LaravelWebMcp\Attributes\WebMcp;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;
use SytxLabs\LaravelWebMcp\Facades\WebMcp as WebMcpFacade;
use SytxLabs\LaravelWebMcp\Tests\Fixtures\PlainTool;
use SytxLabs\LaravelWebMcp\Tests\Fixtures\WeatherTool;

class ScalarGroupServer extends Server
{
    protected array $tools = [ToolSearch::class => 'nope'];
}

class WrongKeyGroupServer extends Server
{
    protected array $tools = [WeatherTool::class => [PlainTool::class]];
}

class BadEntryServer extends Server
{
    protected array $tools = [123];
}

#[WebMcp(exposeAll: true)]
class MisplacedExposeAllTool extends Tool
{
}

class MisplacedAttributeServer extends Server
{
    protected array $tools = [MisplacedExposeAllTool::class];
}

it('rejects malformed server definitions', function (string $class, string $message): void {
    WebMcpFacade::server('bad', $class);

    expect(fn () => WebMcpFacade::manifest('bad'))->toThrow(InvalidWebMcpConfigurationException::class, $message);
})->with([
    'scalar tool group' => [ScalarGroupServer::class, 'class names or instances'],
    'wrong group key' => [WrongKeyGroupServer::class, 'ToolSearch::class as their key'],
    'bad entry' => [BadEntryServer::class, 'class names or instances'],
    'server-only attribute on a tool' => [MisplacedAttributeServer::class, 'only valid on a Server class'],
]);

#[Description('Debuggable tool.')]
#[WebMcp(mode: WebMcpMode::Session, debugging: true)]
class DebugTool extends Tool
{
    public function outputSchema(JsonSchema $schema): array
    {
        return ['ok' => $schema->boolean()->required()];
    }

    public function handle(Request $request): Response
    {
        return Response::text('debug');
    }
}

#[Description('Debuggable resource.')]
#[Uri('file://debug')]
#[WebMcp(mode: WebMcpMode::Session, debugging: true)]
class DebugResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text('debug');
    }
}

#[Description('Never registered.')]
#[Uri('file://hidden')]
#[WebMcp(mode: WebMcpMode::Session)]
class HiddenResource extends Resource
{
    public function shouldRegister(): bool
    {
        return false;
    }

    public function handle(Request $request): Response
    {
        return Response::text('hidden');
    }
}

class OddServer extends Server
{
    protected array $tools = [DebugTool::class];

    protected array $resources = [DebugResource::class, HiddenResource::class];
}

it('compiles debugging flags, output schemas and skips unregistered entries', function (): void {
    config(['webmcp.features.output_schema' => true]);
    WebMcpFacade::server('odd', OddServer::class);

    $manifest = WebMcpFacade::manifest('odd');
    $tool = $manifest->tool('debug-tool');

    expect($tool)->not->toBeNull()
        ->and($tool->annotations['debugging'] ?? null)->toBeTrue()
        ->and($manifest->toolNames())->toHaveCount(2)
        ->and($manifest->toolNames())->not->toContain('hidden-resource');
});
