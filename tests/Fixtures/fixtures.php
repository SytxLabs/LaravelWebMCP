<?php

declare(strict_types=1);

// Several small laravel/mcp fixtures in one file; required from tests/Pest.php.

namespace SytxLabs\LaravelWebMcp\Tests\Fixtures;

use Generator;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Enums\CacheScope;
use Laravel\Mcp\Enums\Role;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Annotations\Audience;
use Laravel\Mcp\Server\Annotations\Priority;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Attributes\Cacheable;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\ToolSearch;
use Laravel\Mcp\Server\Ui\Enums\Visibility;
use Laravel\Mcp\Support\UriTemplate;
use RuntimeException;
use SytxLabs\LaravelWebMcp\Attributes\WebMcp;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Contracts\AuthorizesWebMcpRead;

// ---- Tools ---------------------------------------------------------------

#[Description('A tool nobody opted in.')]
class PlainTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('plain');
    }
}

#[Description('Current weather for a city.')]
#[IsIdempotent]
#[IsReadOnly]
#[WebMcp(mode: WebMcpMode::Session)]
class WeatherTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->description('City name')->required(),
            'units' => $schema->string()->enum(['metric', 'imperial']),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['city' => 'required|string']);

        return Response::text('sunny in '.$request->get('city'));
    }
}

#[Description('Deletes a record.')]
#[IsDestructive]
#[WebMcp]
class DeleteRecordTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('deleted');
    }
}

#[Description('Never registered.')]
#[WebMcp]
class HiddenTool extends Tool
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

#[Description('Renamed tool.')]
#[WebMcp(name: 'custom.name', mode: WebMcpMode::Bridge, untrusted: true)]
class RenamedTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('renamed');
    }
}

#[Description('Inherits everything.')]
#[WebMcp]
class InheritingTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('inherit');
    }
}

#[Description('Tool in a ToolSearch catalog.')]
#[WebMcp]
class CatalogTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('catalog');
    }
}

#[Description('Catalog tool without opt-in.')]
class CatalogPlainTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('catalog plain');
    }
}

#[Description('Dashboard UI.')]
class DashboardApp extends AppResource
{
    public function handle(Request $request): Response
    {
        return Response::text('<html></html>');
    }
}

#[Description('Only the UI may call this.')]
#[RendersApp(resource: DashboardApp::class, visibility: [Visibility::App])]
#[WebMcp]
class AppOnlyTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('app only');
    }
}

#[Description('Only the UI may call this, explicitly allowed.')]
#[RendersApp(resource: DashboardApp::class, visibility: [Visibility::App])]
#[WebMcp(allowAppOnly: true)]
class AllowedAppOnlyTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('app only allowed');
    }
}

#[Description('Model visible app tool.')]
#[RendersApp(resource: DashboardApp::class)]
#[WebMcp]
class ModelAppTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('model app');
    }
}

#[Description('Exposes itself to another origin.')]
#[WebMcp(exposedTo: ['https://chat.example.com'])]
class ExposedToTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('exposed');
    }
}

#[Description('Exposes itself to an origin that is not in the allowlist.')]
#[WebMcp(exposedTo: ['https://evil.example.com'])]
class ExposedToUnlistedTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('exposed');
    }
}

#[Description('Invalid name.')]
#[Name('has spaces')]
#[WebMcp]
class InvalidNameTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('x');
    }
}

// ---- Resources -----------------------------------------------------------

#[Audience(Role::User)]
#[Description('Application settings.')]
#[Priority(0.8)]
#[Uri('file://resources/settings')]
#[WebMcp]
class SettingsResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text('{"theme":"dark"}');
    }
}

#[Description('A user document.')]
#[WebMcp]
class UserDocResource extends Resource implements AuthorizesWebMcpRead, HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('file://users/{userId}/docs/{docId}');
    }

    public function authorizeWebMcpRead(Request $request, array $variables): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        return Response::text('doc '.$request->get('docId'));
    }
}

#[Description('Template without authorization.')]
#[WebMcp]
class OpenTemplateResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('file://open/{id}');
    }

    public function handle(Request $request): Response
    {
        return Response::text('open');
    }
}

#[Description('Resource that asks for confirmation.')]
#[WebMcp(confirm: true)]
class ConfirmingResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text('nope');
    }
}

#[Description('Resource without opt-in.')]
class PlainResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text('plain resource');
    }
}

#[Description('Named like a tool.')]
#[WebMcp(name: 'get-weather')]
class CollidingResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text('collision');
    }
}

// ---- Prompts -------------------------------------------------------------

#[Description('A prompt.')]
class HelloPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        return Response::text('hello');
    }
}

#[Description('A prompt that wrongly carries the attribute.')]
#[WebMcp]
class AttributedPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        return Response::text('hello');
    }
}

// ---- Servers -------------------------------------------------------------

#[Description('Tool named get-weather.')]
#[Name('get-weather')]
#[WebMcp]
class GetWeatherNamedTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('x');
    }
}

class BasicServer extends Server
{
    protected array $tools = [
        PlainTool::class,
        WeatherTool::class,
        DeleteRecordTool::class,
        HiddenTool::class,
        RenamedTool::class,
        AppOnlyTool::class,
        AllowedAppOnlyTool::class,
        ModelAppTool::class,
    ];

    protected array $resources = [
        DashboardApp::class,
        SettingsResource::class,
        UserDocResource::class,
        OpenTemplateResource::class,
        PlainResource::class,
    ];

    protected array $prompts = [
        HelloPrompt::class,
    ];
}

class CatalogServer extends Server
{
    protected array $tools = [
        ToolSearch::class => [
            CatalogTool::class,
            CatalogPlainTool::class,
        ],
        WeatherTool::class,
    ];
}

#[WebMcp(mode: WebMcpMode::Bridge, confirm: true)]
class ServerDefaultsServer extends Server
{
    protected array $tools = [
        InheritingTool::class,
        PlainTool::class,
        RenamedTool::class,
    ];
}

#[WebMcp(exposeAll: true)]
class ExposeAllServer extends Server
{
    protected array $tools = [
        PlainTool::class,
        WeatherTool::class,
    ];

    protected array $resources = [
        PlainResource::class,
        DashboardApp::class,
    ];

    protected array $prompts = [
        HelloPrompt::class,
    ];
}

#[WebMcp(prefix: 'shop_')]
class PrefixedServer extends Server
{
    protected array $tools = [
        WeatherTool::class,
    ];
}

class OriginServer extends Server
{
    protected array $tools = [
        ExposedToTool::class,
    ];
}

class UnlistedOriginServer extends Server
{
    protected array $tools = [
        ExposedToUnlistedTool::class,
    ];
}

class InvalidNameServer extends Server
{
    protected array $tools = [
        InvalidNameTool::class,
    ];
}

class CollisionServer extends Server
{
    protected array $tools = [
        GetWeatherNamedTool::class,
    ];
}

class ResourceCollisionServer extends Server
{
    protected array $tools = [
        GetWeatherNamedTool::class,
    ];

    protected array $resources = [
        CollidingResource::class,
    ];
}

class BadPromptServer extends Server
{
    protected array $prompts = [
        AttributedPrompt::class,
    ];
}

class BadResourceServer extends Server
{
    protected array $resources = [
        ConfirmingResource::class,
    ];
}

class WeatherOnlyServer extends Server
{
    protected array $tools = [
        WeatherTool::class,
    ];
}

// ---- Execution fixtures ---------------------------------------------------

#[Description('Streams notifications and several parts.')]
#[WebMcp]
class StreamingTool extends Tool
{
    public function handle(Request $request): Generator
    {
        yield Response::notification('notifications/message', ['level' => 'info', 'data' => 'working']);
        yield Response::text('part one');
        yield Response::text('part two');
    }
}

#[Description('Always throws.')]
#[WebMcp]
class FailingTool extends Tool
{
    public function handle(Request $request): Response
    {
        throw new RuntimeException('secret internal detail');
    }
}

#[Description('Returns a Response::error.')]
#[WebMcp]
class ErroringTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::error('Cannot do that.');
    }
}

#[Description('Returns structured content.')]
#[WebMcp]
class StructuredTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        return Response::structured(['temp' => 21, 'unit' => 'C']);
    }
}

#[Description('Returns an image.')]
#[WebMcp]
class ImageTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::image(base64_encode('pngbytes'), 'image/png');
    }
}

#[Description('Reads who is calling.')]
#[WebMcp]
class WhoAmITool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('user:'.($request->user()?->getAuthIdentifier() ?? 'guest'));
    }
}

#[Description('Only for logged-in users.')]
#[WebMcp]
class AuthedTool extends Tool
{
    public function shouldRegister(Request $request): bool
    {
        return $request->user() !== null;
    }

    public function handle(Request $request): Response
    {
        return Response::text('authed');
    }
}

#[Description('Denies every read.')]
#[WebMcp]
class DenyingResource extends Resource implements AuthorizesWebMcpRead, HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('file://denied/{id}');
    }

    public function authorizeWebMcpRead(Request $request, array $variables): bool
    {
        return false;
    }

    public function handle(Request $request): Response
    {
        return Response::text('should never be read');
    }
}

#[Description('A binary resource.')]
#[MimeType('image/png')]
#[Uri('file://resources/logo')]
#[WebMcp]
class BlobResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::blob('binarydata');
    }
}

#[Description('Static resource with an authorization check.')]
#[Uri('file://resources/guarded')]
#[WebMcp]
class GuardedResource extends Resource implements AuthorizesWebMcpRead
{
    public function authorizeWebMcpRead(Request $request, array $variables): bool
    {
        return $request->user() !== null;
    }

    public function handle(Request $request): Response
    {
        return Response::text('guarded content');
    }
}

#[Description('Destructive tool served over the Bridge.')]
#[IsDestructive]
#[WebMcp(mode: WebMcpMode::Bridge)]
class BridgeDeleteTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('bridge deleted');
    }
}

#[Description('Resource served over the Bridge.')]
#[Uri('file://resources/bridge')]
#[WebMcp(mode: WebMcpMode::Bridge)]
class BridgeResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text('bridge resource');
    }
}

#[Description("</script><script>alert(1)</script> {{ 1 + 1 }} @php echo 'x' @endphp & 'quotes' \"double\"")]
#[WebMcp]
class HostileTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('hostile');
    }
}

class HostileServer extends Server
{
    protected array $tools = [
        HostileTool::class,
    ];
}

// ---- Declarative form fixtures ----------------------------------------------

#[Description('Search products in the shop.')]
#[IsReadOnly]
#[WebMcp]
class SearchProductsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('What to search for')->min(2)->max(50)->required(),
            'max_price' => $schema->number()->description('Maximum price')->min(0),
            'category' => $schema->string()->enum(['shoes', 'hats'])->description('Product category'),
            'in_stock' => $schema->boolean()->description('Only items in stock'),
            'email' => $schema->string()->format('email')->description('Where to send the result'),
        ];
    }

    public function handle(Request $request): Response
    {
        return Response::text('found: '.$request->get('query'));
    }
}

#[Description('Places an order.')]
#[WebMcp]
class CreateOrderTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'sku' => $schema->string()->description('Product SKU')->required(),
            'quantity' => $schema->integer()->min(1)->max(10)->default(1)->description('How many'),
        ];
    }

    public function handle(Request $request): Response
    {
        return Response::text('ordered '.$request->get('sku').' x'.$request->get('quantity', 1));
    }
}

#[Description('Cancels an order for good.')]
#[IsDestructive]
#[WebMcp]
class CancelOrderTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return ['order_id' => $schema->integer()->description('Order to cancel')->required()];
    }

    public function handle(Request $request): Response
    {
        return Response::text('cancelled '.$request->get('order_id'));
    }
}

#[Description('Served over the Bridge.')]
#[WebMcp(mode: WebMcpMode::Bridge)]
class BridgeFormTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('bridge');
    }
}

#[Description('Takes structured input.')]
#[WebMcp]
class ComplexInputTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'filters' => $schema->object(['color' => $schema->string()])->description('Nested filters'),
            'tags' => $schema->array()->items($schema->string())->description('Tags'),
        ];
    }

    public function handle(Request $request): Response
    {
        return Response::text('complex');
    }
}

#[Description('He said "hi" & <left> \'quote\'')]
#[WebMcp]
class QuoteTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return ['q' => $schema->string()->description('Say "this" & <that>')->required()];
    }

    public function handle(Request $request): Response
    {
        return Response::text('quoted');
    }
}

class FormServer extends Server
{
    protected array $tools = [
        SearchProductsTool::class,
        CreateOrderTool::class,
        CancelOrderTool::class,
        BridgeFormTool::class,
        ComplexInputTool::class,
        QuoteTool::class,
        HiddenTool::class,
    ];
}

// ---- Cache / localization fixtures -------------------------------------------

#[Description('Counts how often shouldRegister runs.')]
#[WebMcp]
class CountingTool extends Tool
{
    public static int $registered = 0;

    public function shouldRegister(): bool
    {
        self::$registered++;

        return true;
    }

    public function handle(Request $request): Response
    {
        return Response::text('counted');
    }
}

#[Description('Depends on state the cache cannot see.')]
#[WebMcp(cache: false)]
class UncacheableTool extends Tool
{
    public static int $registered = 0;

    public function shouldRegister(): bool
    {
        self::$registered++;

        return true;
    }

    public function handle(Request $request): Response
    {
        return Response::text('uncacheable');
    }
}

class CacheServer extends Server
{
    protected array $tools = [CountingTool::class, AuthedTool::class];
}

class UncacheableServer extends Server
{
    protected array $tools = [CountingTool::class, UncacheableTool::class];
}

#[Cacheable(ttlMs: 2000)]
class ShortCacheServer extends Server
{
    protected array $tools = [CountingTool::class];
}

#[Cacheable(scope: CacheScope::Public)]
#[WebMcp(sharedCache: true)]
class SharedCacheServer extends Server
{
    protected array $tools = [CountingTool::class];
}

#[Description('webmcp-test.tool.description')]
#[Title('webmcp-test.tool.title')]
#[WebMcp]
class TranslatedTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->description('webmcp-test.param.city')->required(),
            'plain' => $schema->string()->description('No translation exists for this text.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return Response::text('translated');
    }
}

#[Audience(Role::User)]
#[Description('webmcp-test.resource.description')]
#[Uri('file://resources/translated')]
#[WebMcp]
class TranslatedResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text('translated resource');
    }
}

class TranslatedServer extends Server
{
    protected array $tools = [TranslatedTool::class];

    protected array $resources = [TranslatedResource::class];
}

class ArrayUserProvider implements UserProvider
{
    public function retrieveById($identifier)
    {
        return in_array((int) $identifier, [1, 2], true) ? new GenericUser(['id' => (int) $identifier]) : null;
    }

    public function retrieveByToken($identifier, $token)
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
    }

    public function retrieveByCredentials(array $credentials)
    {
        return null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return false;
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
    }
}

class ExecServer extends Server
{
    protected array $tools = [
        WeatherTool::class,
        DeleteRecordTool::class,
        StreamingTool::class,
        FailingTool::class,
        ErroringTool::class,
        StructuredTool::class,
        ImageTool::class,
        WhoAmITool::class,
        AuthedTool::class,
        RenamedTool::class,
        BridgeDeleteTool::class,
        PlainTool::class,
    ];

    protected array $prompts = [
        HelloPrompt::class,
    ];

    protected array $resources = [
        BridgeResource::class,
        SettingsResource::class,
        UserDocResource::class,
        DenyingResource::class,
        BlobResource::class,
        GuardedResource::class,
    ];
}
