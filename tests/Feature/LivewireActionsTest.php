<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\ViewException;
use Livewire\Component;
use Livewire\Livewire;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpAction;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;
use SytxLabs\LaravelWebMcp\Exceptions\WebMcpNameCollisionException;
use SytxLabs\LaravelWebMcp\Livewire\ActionManifest;
use SytxLabs\LaravelWebMcp\Livewire\ActionSchema;
use SytxLabs\LaravelWebMcp\Manifest\NameRegistry;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;
use SytxLabs\LaravelWebMcp\View\NonceResolver;

beforeEach(function () {
    Livewire::component('cart', Fixtures\CartComponent::class);
    NonceResolver::using(null);
});

function actionManifest(string $component): array
{
    $instance = new $component();
    $instance->setId('test-id');

    return app(ActionManifest::class)->for($instance);
}

function toolsByName(array $manifest): array
{
    return collect($manifest['tools'])->keyBy('name')->all();
}

function schemaOf(string $component, string $method): array
{
    $reflection = new ReflectionMethod($component, $method);
    $attribute = $reflection->getAttributes(WebMcpAction::class)[0]->newInstance();

    return app(ActionSchema::class)->for($reflection, $attribute);
}

function embeddedActions(string $html): array
{
    preg_match('#<script type="application/json" data-webmcp-livewire data-webmcp-component="([^"]*)"[^>]*>(.*?)</script>#s', $html, $match);

    return ['id' => $match[1] ?? null, 'payload' => json_decode($match[2] ?? 'null', true, flags: JSON_THROW_ON_ERROR)];
}

// ---- manifest ---------------------------------------------------------------------------

it('lists only methods with #[WebMcpAction] (opt-in per method)', function () {
    $tools = toolsByName(actionManifest(Fixtures\CartComponent::class));

    expect(array_keys($tools))->toBe(['cart-component.add-to-cart', 'cart-component.clear', 'cart.list']);
});

it('derives the tool dictionary from the method signature', function () {
    $tool = toolsByName(actionManifest(Fixtures\CartComponent::class))['cart-component.add-to-cart'];

    expect($tool['description'])->toBe('Adds a product to the cart')
        ->and($tool['title'])->toBe('Add To Cart')
        ->and($tool['kind'])->toBe('livewire')
        ->and($tool['confirm'])->toBeFalse()
        ->and($tool['inputSchema'])->toBe([
            'type' => 'object',
            'properties' => [
                'productId' => ['type' => 'integer'],
                'qty' => ['type' => 'integer', 'description' => 'How many', 'default' => 1],
            ],
            'required' => ['productId'],
        ])
        ->and($tool['livewire'])->toBe(['method' => 'addToCart', 'params' => ['productId', 'qty'], 'defaults' => ['qty' => 1], 'variadic' => null]);
});

it('maps attribute flags to annotations and confirm', function () {
    $tools = toolsByName(actionManifest(Fixtures\CartComponent::class));

    expect($tools['cart-component.clear']['annotations'])->toBe(['consequentialHint' => true])
        ->and($tools['cart-component.clear']['confirm'])->toBeTrue()
        ->and($tools['cart.list']['annotations'])->toBe(['readOnlyHint' => true])
        ->and($tools['cart-component.add-to-cart'])->not->toHaveKey('annotations');
});

it('serializes parameterless actions with an object schema', function () {
    $tool = toolsByName(actionManifest(Fixtures\CartComponent::class))['cart-component.clear'];

    expect(json_encode($tool['inputSchema']))->toBe('{"type":"object","properties":{},"required":[]}')
        ->and(json_encode($tool['livewire']))->toBe('{"method":"clear","params":[],"defaults":{},"variadic":null}');
});

it('derives JSON Schema types from PHP types', function () {
    $schema = schemaOf(Fixtures\TypesComponent::class, 'types');
    $p = $schema['schema']['properties'];

    expect($p['i'])->toBe(['type' => 'integer'])
        ->and($p['f'])->toBe(['type' => 'number'])
        ->and($p['s'])->toBe(['type' => 'string'])
        ->and($p['b'])->toBe(['type' => 'boolean'])
        ->and($p['a'])->toBe(['type' => 'array'])
        ->and($p['priority'])->toBe(['type' => 'string', 'enum' => ['low', 'high']])
        ->and($p['level'])->toBe(['type' => 'integer', 'enum' => [1, 2]])
        ->and($p['nullable'])->toBe(['type' => ['string', 'null']])
        ->and($p['union'])->toBe(['type' => ['string', 'integer']])   // PHP's canonical union order
        ->and($p['any'])->toBe([])
        ->and($p['withDefault'])->toBe(['type' => 'string', 'default' => 'x'])
        ->and($p['list'])->toBe(['type' => 'array', 'default' => ['a']])
        ->and($p['maybe'])->toBe(['type' => ['integer', 'null'], 'default' => null])
        ->and($p['rest'])->toBe(['type' => 'array', 'items' => ['type' => 'integer']]);
});

it('marks only parameters without default, variadic or nullable as required', function () {
    $schema = schemaOf(Fixtures\TypesComponent::class, 'types');

    // `mixed` accepts null, so it is not required either
    expect($schema['schema']['required'])->toBe(['i', 'f', 's', 'b', 'a', 'priority', 'level', 'union'])
        ->and($schema['params'])->toBe(['i', 'f', 's', 'b', 'a', 'priority', 'level', 'nullable', 'union', 'any', 'withDefault', 'list', 'maybe', 'rest'])
        ->and($schema['defaults'])->toBe(['withDefault' => 'x', 'list' => ['a'], 'maybe' => null])
        ->and($schema['variadic'])->toBe('rest');
});

it('maps Eloquent model parameters to their key', function () {
    $property = schemaOf(Fixtures\TypesComponent::class, 'view')['schema']['properties']['product'];

    expect($property['type'])->toBe(['integer', 'string'])
        ->and($property['description'])->toContain('Product');
});

it('lets an explicit schema replace the derived one but keeps parameter order', function () {
    $schema = schemaOf(Fixtures\TypesComponent::class, 'search');

    expect($schema['schema'])->toBe([
        'type' => 'object',
        'properties' => ['q' => ['type' => 'string', 'minLength' => 2]],
        'required' => ['q'],
    ])->and($schema['params'])->toBe(['q', 'extra']);
});

it('fails clearly for parameters without a JSON Schema mapping', function (string $component) {
    actionManifest($component);
})->with([
    'object type' => Fixtures\UnmappableComponent::class,
    'pure enum' => Fixtures\PureEnumComponent::class,
])->throws(InvalidWebMcpConfigurationException::class, 'explicit schema');

it('refuses lifecycle methods', function (string $component) {
    actionManifest($component);
})->with(['mount' => [Fixtures\LifecycleComponent::class], 'updated hook' => [Fixtures\UpdatedHookComponent::class]])
    ->throws(InvalidWebMcpConfigurationException::class, 'not allowed');

it('makes names unique per instance with webMcpInstanceKey()', function () {
    $a = new Fixtures\KeyedComponent();
    $a->setId('a');
    $a->id = 5;
    $b = new Fixtures\KeyedComponent();
    $b->setId('b');
    $b->id = 6;

    $manifest = app(ActionManifest::class);

    expect($manifest->for($a)['tools'][0]['name'])->toBe('keyed-component.archive.row-5')
        ->and($manifest->for($b)['tools'][0]['name'])->toBe('keyed-component.archive.row-6');
});

it('detects two instances of the same component without an instance key in one request', function () {
    $first = new Fixtures\CartComponent();
    $first->setId('1');
    $second = new Fixtures\CartComponent();
    $second->setId('2');

    $manifest = app(ActionManifest::class);
    $manifest->for($first);
    $manifest->for($second);
})->throws(WebMcpNameCollisionException::class);

it('hides actions through webMcpAvailable()', function () {
    $component = new Fixtures\GuardedComponent();
    $component->setId('g');

    expect(array_column(app(ActionManifest::class)->for($component)['tools'], 'name'))->toBe(['guarded-component.look']);

    app()->forgetInstance(NameRegistry::class);
    $admin = new Fixtures\GuardedComponent();
    $admin->setId('g2');
    $admin->admin = true;

    expect(array_column(app(ActionManifest::class)->for($admin)['tools'], 'name'))->toBe(['guarded-component.look', 'guarded-component.purge']);
});

it('localizes descriptions and parameter descriptions that are translation keys', function () {
    app('translator')->addLines(['webmcp.cart.add' => 'Fügt ein Produkt hinzu', 'webmcp.cart.qty' => 'Wie viele'], 'de');
    app()->setLocale('de');

    $component = new class extends Component
    {
        #[WebMcpAction(description: 'webmcp.cart.add', name: 'loc.add', parameters: ['qty' => 'webmcp.cart.qty'])]
        public function add(int $qty = 1): void
        {
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };
    $component->setId('loc');

    $tool = app(ActionManifest::class)->for($component)['tools'][0];

    expect($tool['description'])->toBe('Fügt ein Produkt hinzu')
        ->and($tool['inputSchema']['properties']['qty']['description'])->toBe('Wie viele')
        ->and($tool['name'])->toBe('loc.add'); // names are never translated
});

// ---- rendering through Livewire ------------------------------------------------------------

it('publishes the actions inside the component HTML', function () {
    $html = Livewire::test(Fixtures\CartComponent::class)->html();

    $embedded = embeddedActions($html);

    expect($embedded['id'])->not->toBeEmpty()
        ->and($embedded['payload']['version'])->toBe(1)
        ->and($embedded['payload']['component'])->toBe($embedded['id'])
        ->and(array_column($embedded['payload']['tools'], 'name'))->toBe(['cart-component.add-to-cart', 'cart-component.clear', 'cart.list']);
});

it('renders nothing for components without actions', function () {
    expect(Livewire::test(Fixtures\EmptyComponent::class)->html())->not->toContain('data-webmcp-livewire');
});

it('can be disabled in config', function () {
    config(['webmcp.livewire.enabled' => false]);

    expect(Livewire::test(Fixtures\CartComponent::class)->html())->not->toContain('data-webmcp-livewire');
});

it('embeds hostile descriptions safely', function () {
    $html = Livewire::test(Fixtures\HostileActionComponent::class)->html();

    expect($html)->not->toContain('<script>alert(1)')
        ->and($html)->not->toContain('</script><script>')
        ->and(embeddedActions($html)['payload']['tools'][0]['description'])->toContain('</script><script>alert(1)</script> {{ 1 + 1 }}');
});

it('adds the CSP nonce', function () {
    Vite::useCspNonce('lw-nonce');

    expect(Livewire::test(Fixtures\CartComponent::class)->html())->toContain('nonce="lw-nonce"');
});

it('keeps class names and paths out of the published payload', function () {
    $html = Livewire::test(Fixtures\CartComponent::class)->html();
    $json = json_encode(embeddedActions($html)['payload']);

    expect($json)->not->toContain('SytxLabs')->and($json)->not->toContain('.php');
});

it('rejects @webmcpActions outside a Livewire component', function () {
    Blade::render('@webmcpActions');
})->throws(ViewException::class, 'inside a Livewire component view');

it('still executes the method through Livewire (checksum and validation stay in charge)', function () {
    Livewire::test(Fixtures\CartComponent::class)
        ->call('addToCart', 5, 2)
        ->assertOk();
});

// ---- validation errors reach the agent ------------------------------------------------------------

it('sends validation errors of a WebMCP action along as an effect (Livewire swallows them otherwise)', function () {
    $test = Livewire::test(Fixtures\ValidatingComponent::class)->call('save', 9);

    expect($test->effects['webmcpErrors'])->toBe(['qty' => ['The qty field must not be greater than 5.']]);
});

it('sends nothing on success', function () {
    $test = Livewire::test(Fixtures\ValidatingComponent::class)->call('save', 3);

    expect($test->effects)->not->toHaveKey('webmcpErrors');
});

it('leaves components without WebMCP actions alone', function () {
    $test = Livewire::test(Fixtures\PlainValidatingComponent::class)->call('save', 9);

    expect($test->effects)->not->toHaveKey('webmcpErrors');
});

it('can switch the hook off with the Livewire setting', function () {
    expect(ActionManifest::hasActions(new Fixtures\ValidatingComponent()))->toBeTrue()
        ->and(ActionManifest::hasActions(new Fixtures\PlainValidatingComponent()))->toBeFalse();
});
