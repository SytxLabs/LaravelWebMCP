<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;
use SytxLabs\LaravelWebMcp\Exceptions\UnmappableSchemaException;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Forms\DeclarativeForms;
use SytxLabs\LaravelWebMcp\Forms\FormField;
use SytxLabs\LaravelWebMcp\Forms\FormFieldMapper;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;

beforeEach(function () {
    WebMcp::server('shop', Fixtures\FormServer::class);
});

function mapField(array $property, bool $required = false): FormField
{
    $schema = ['type' => 'object', 'properties' => ['field' => $property], 'required' => $required ? ['field'] : []];

    return (new FormFieldMapper())->map('tool', $schema)[0];
}

function form(string $blade): string
{
    return Blade::render($blade);
}

// ---- schema -> field mapping ------------------------------------------------------------------

it('maps strings to text inputs with length and pattern constraints', function () {
    $field = mapField(['type' => 'string', 'minLength' => 2, 'maxLength' => 50, 'pattern' => '^[a-z]+$', 'description' => 'A name'], required: true);

    expect($field->kind)->toBe('input')
        ->and($field->inputType)->toBe('text')
        ->and($field->attributes)->toBe(['minlength' => 2, 'maxlength' => 50, 'pattern' => '^[a-z]+$'])
        ->and($field->required)->toBeTrue()
        ->and($field->description)->toBe('A name')
        ->and($field->label)->toBe('Field');
});

it('maps string formats to input types', function (string $format, string $type) {
    expect(mapField(['type' => 'string', 'format' => $format])->inputType)->toBe($type);
})->with([
    'email' => ['email', 'email'],
    'uri' => ['uri', 'url'],
    'date' => ['date', 'date'],
    'date-time' => ['date-time', 'datetime-local'],
    'time' => ['time', 'time'],
    'unknown falls back to text' => ['hostname', 'text'],
]);

it('maps numbers with bounds and step', function () {
    expect(mapField(['type' => 'integer', 'minimum' => 1, 'maximum' => 10])->attributes)->toBe(['min' => 1, 'max' => 10, 'step' => 1])
        ->and(mapField(['type' => 'number', 'multipleOf' => 0.5, 'minimum' => 0])->attributes)->toBe(['min' => 0, 'step' => 0.5])
        ->and(mapField(['type' => 'number'])->attributes)->toBe(['step' => 'any'])
        ->and(mapField(['type' => 'integer'])->inputType)->toBe('number');
});

it('maps booleans to checkboxes and enums to selects', function () {
    $checkbox = mapField(['type' => 'boolean', 'default' => true]);
    $select = mapField(['type' => 'string', 'enum' => ['shoes', 'high_hats']]);

    expect($checkbox->kind)->toBe('checkbox')->and($checkbox->default)->toBeTrue()
        ->and($select->kind)->toBe('select')
        ->and($select->options)->toBe([['value' => 'shoes', 'label' => 'Shoes'], ['value' => 'high_hats', 'label' => 'High Hats']]);
});

it('maps integer enums and infers the type from the enum values', function () {
    $field = mapField(['enum' => [1, 2, 3]]);

    expect($field->kind)->toBe('select')->and($field->type)->toBe('integer')->and($field->options[0])->toBe(['value' => '1', 'label' => '1']);
});

it('treats a nullable type as the type itself', function () {
    expect(mapField(['type' => ['integer', 'null']])->type)->toBe('integer');
});

it('keeps the default value and a custom title as label', function () {
    $field = mapField(['type' => 'string', 'default' => 'abc', 'title' => 'Pick one']);

    expect($field->default)->toBe('abc')->and($field->label)->toBe('Pick one');
});

it('skips properties listed in except', function () {
    $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], 'required' => []];

    expect(array_map(fn (FormField $f) => $f->name, (new FormFieldMapper())->map('t', $schema, ['a'])))->toBe(['b']);
});

it('returns no fields for an empty schema', function () {
    expect((new FormFieldMapper())->map('t', ['type' => 'object', 'properties' => new stdClass(), 'required' => []]))->toBe([]);
});

it('refuses property types that have no form control', function (array $property, string $reason) {
    mapField($property);
})->with([
    'object' => [['type' => 'object', 'properties' => []], 'nested objects'],
    'array' => [['type' => 'array', 'items' => ['type' => 'string']], 'arrays'],
    'array of enum' => [['type' => 'array', 'items' => ['enum' => ['a']]], 'arrays'],
    'oneOf' => [['oneOf' => [['type' => 'string'], ['type' => 'integer']]], 'oneOf'],
    'anyOf' => [['anyOf' => [['type' => 'string']]], 'anyOf'],
    'allOf' => [['allOf' => [['type' => 'string']]], 'allOf'],
    '$ref' => [['$ref' => '#/defs/x'], '$ref'],
    'binary' => [['type' => 'string', 'format' => 'binary'], 'file uploads'],
    'union of types' => [['type' => ['string', 'integer']], 'union'],
    'no type' => [['description' => 'untyped'], 'no usable'],
    'non-scalar enum' => [['type' => 'string', 'enum' => [['a']]], 'non-scalar'],
])->throws(UnmappableSchemaException::class);

it('names the tool, the property and the way out in the error', function () {
    mapField(['type' => 'object']);
})->throws(UnmappableSchemaException::class, 'property [field]');

// ---- component ----------------------------------------------------------------------------------

it('renders a declarative form with the explainer attributes', function () {
    $html = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\SearchProductsTool::class" action="/search" method="get" submit="Search" />');

    expect($html)->toContain('toolname="search-products-tool"')
        ->and($html)->toContain('tooldescription="Search products in the shop."')
        ->and($html)->toContain('toolautosubmit')                       // read-only tool
        ->and($html)->toContain('action="/search"')
        ->and($html)->toContain('method="get"')
        ->and($html)->toContain('data-webmcp-endpoint="/webmcp/shop/tools/search-products-tool"')
        ->and($html)->not->toContain('name="_token"')                  // no CSRF on GET
        ->and($html)->toContain('>Search</button>');
});

it('renders controls with name and toolparamdescription', function () {
    $html = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\SearchProductsTool::class" method="get" />');

    expect($html)->toMatch('/<input type="text" id="webmcp-search-products-tool-query" name="query"\s+required\s+toolparamdescription="What to search for"\s+minlength="2"\s+maxlength="50"/')
        ->and($html)->toContain('name="max_price"')
        ->and($html)->toMatch('/type="number"[^>]*name="max_price"[^>]*toolparamdescription="Maximum price"[^>]*min="0"[^>]*step="any"/')
        ->and($html)->toMatch('/<select id="[^"]*" name="category"[^>]*toolparamdescription="Product category"/')
        ->and($html)->toContain('<option value="shoes"')
        ->and($html)->toMatch('/type="checkbox"[^>]*name="in_stock"[^>]*toolparamdescription="Only items in stock"/')
        ->and($html)->toMatch('/type="email"[^>]*name="email"/')
        ->and($html)->toContain('<label for="webmcp-search-products-tool-query">Query</label>');
});

it('lists the field types for the browser script', function () {
    $html = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\SearchProductsTool::class" method="get" />');

    preg_match('/data-webmcp-types="([^"]*)"/', $html, $match);
    $types = json_decode(html_entity_decode($match[1]), true);

    expect($types)->toBe(['query' => 'string', 'max_price' => 'number', 'category' => 'string', 'in_stock' => 'boolean', 'email' => 'string']);
});

it('adds CSRF and method spoofing for non-GET forms and does not auto-submit write tools', function () {
    $post = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\CreateOrderTool::class" action="/orders" />');

    expect($post)->toContain('method="post"')
        ->and($post)->toContain('name="_token"')
        ->and($post)->not->toContain('toolautosubmit')
        ->and($post)->not->toContain('name="_method"')
        ->and($post)->toContain('value="1"'); // integer default

    $put = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\CreateOrderTool::class" action="/orders/1" method="put" />');

    expect($put)->toContain('method="post"')->and($put)->toContain('name="_method" value="PUT"');
});

it('lets you set auto-submit explicitly, except for consequential tools', function () {
    $explicit = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\CreateOrderTool::class" :autosubmit="true" />');
    $off = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\SearchProductsTool::class" :autosubmit="false" method="get" />');

    expect($explicit)->toContain('toolautosubmit')->and($off)->not->toContain('toolautosubmit');

    form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\CancelOrderTool::class" :autosubmit="true" />');
})->throws(ViewException::class, 'is consequential');

it('allows auto-submit for consequential tools only when configured', function () {
    config(['webmcp.forms.allow_autosubmit_consequential' => true]);

    expect(form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\CancelOrderTool::class" :autosubmit="true" />'))->toContain('toolautosubmit');
});

it('marks consequential forms so the script asks for confirmation', function () {
    $html = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\CancelOrderTool::class" />');

    expect($html)->toContain('data-webmcp-confirm')->and($html)->not->toContain('toolautosubmit');
});

it('can omit generated fields and exclude single properties', function () {
    $bare = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\SearchProductsTool::class" method="get" :controls="false"><input name="query" type="text"></x-webmcp::form>');

    expect($bare)->toContain('toolname="search-products-tool"')
        ->and($bare)->toContain('<input name="query" type="text">')
        ->and($bare)->not->toContain('webmcp-field');

    $partial = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\SearchProductsTool::class" method="get" :omit="[\'email\', \'category\']" />');

    expect($partial)->not->toContain('name="email"')->and($partial)->not->toContain('name="category"')->and($partial)->toContain('name="query"');
});

it('passes extra attributes through but never lets them override the tool attributes', function () {
    $html = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\SearchProductsTool::class" method="get" class="search" id="f1" toolname="evil" />');

    expect($html)->toContain('class="search"')->and($html)->toContain('id="f1"')
        ->and($html)->toContain('toolname="search-products-tool"')->and($html)->not->toContain('evil');
});

it('escapes tool metadata in attributes', function () {
    $html = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\QuoteTool::class" method="get" />');

    expect($html)->toContain('tooldescription="He said &quot;hi&quot; &amp; &lt;left&gt; &#039;quote&#039;"')
        ->and($html)->toContain('toolparamdescription="Say &quot;this&quot; &amp; &lt;that&gt;"')
        ->and($html)->not->toContain('<left>');
});

it('refuses tools that are not exposed, with the reason', function () {
    form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\HiddenTool::class" />');
})->throws(ViewException::class, 'should-register');

it('refuses tools that no registered server knows', function () {
    form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\PlainTool::class" />');
})->throws(ViewException::class, 'is not an exposed WebMCP tool');

it('refuses Bridge-mode tools because forms submit through the Session routes', function () {
    form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\BridgeFormTool::class" />');
})->throws(ViewException::class, 'Bridge mode');

it('refuses tools with inputs that cannot be fields unless they are excluded', function () {
    form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\ComplexInputTool::class" />');
})->throws(ViewException::class, 'cannot be rendered as a form field');

it('renders tools with unsupported inputs when those inputs are excluded', function () {
    $html = form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\ComplexInputTool::class" :omit="[\'filters\', \'tags\']" />');

    expect($html)->toContain('toolname="complex-input-tool"');
});

it('can be scoped to a server', function () {
    WebMcp::server('other', Fixtures\WeatherOnlyServer::class);

    expect(form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\SearchProductsTool::class" server="shop" method="get" />'))
        ->toContain('toolname="search-products-tool"');

    form('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\SearchProductsTool::class" server="other" method="get" />');
})->throws(ViewException::class, 'server [other]');

it('needs the Session routes', function () {
    // Routes are registered at boot; simulate their absence by asking for a route that does not exist.
    expect(Route::has('webmcp.tools'))->toBeTrue();
});

// ---- annotating existing forms ----------------------------------------------------------------------

it('annotates an existing form with @webmcpForm and its controls with @webmcpParam', function () {
    $html = form(<<<'BLADE'
        <form method="get" action="/find" @webmcpForm(\SytxLabs\LaravelWebMcp\Tests\Fixtures\SearchProductsTool::class)>
            <input name="query" @webmcpParam(\SytxLabs\LaravelWebMcp\Tests\Fixtures\SearchProductsTool::class, 'query')>
            <input name="other" @webmcpParam(\SytxLabs\LaravelWebMcp\Tests\Fixtures\QuoteTool::class, 'q')>
        </form>
        BLADE);

    expect($html)->toContain('toolname="search-products-tool"')
        ->and($html)->toContain('tooldescription="Search products in the shop."')
        ->and($html)->toContain('toolautosubmit')
        ->and($html)->toContain('data-webmcp-endpoint="/webmcp/shop/tools/search-products-tool"')
        ->and($html)->toContain('<input name="query" toolparamdescription="What to search for">');
});

it('fails for @webmcpParam with an unknown property', function () {
    form('<input @webmcpParam(\SytxLabs\LaravelWebMcp\Tests\Fixtures\SearchProductsTool::class, \'nope\')>');
})->throws(ViewException::class, 'no input property');

it('exposes the helpers on the service', function () {
    $forms = app(DeclarativeForms::class);

    expect((string) $forms->attributes(Fixtures\SearchProductsTool::class))->toContain('toolname="search-products-tool"')
        ->and((string) $forms->param(Fixtures\SearchProductsTool::class, 'query'))->toBe('toolparamdescription="What to search for"')
        ->and(fn () => $forms->param(Fixtures\SearchProductsTool::class, 'nope'))->toThrow(InvalidWebMcpConfigurationException::class);
});

it('refuses a javascript:, data: or vbscript: form action', function (string $action) {
    form('<x-webmcp::form :tool="\SytxLabs\LaravelWebMcp\Tests\Fixtures\SearchProductsTool::class" action="'.$action.'" method="get" />');
})->with([
    'javascript' => 'javascript:alert(1)',
    'mixed case' => 'JaVaScRiPt:alert(1)',
    'leading whitespace' => ' javascript:alert(1)',
    'data' => 'data:text/html,x',
    'vbscript' => 'vbscript:x',
])->throws(ViewException::class, 'must not use a javascript:');

it('accepts ordinary actions', function () {
    expect(form('<x-webmcp::form :tool="\SytxLabs\LaravelWebMcp\Tests\Fixtures\SearchProductsTool::class" action="/search?x=1" method="get" />'))
        ->toContain('action="/search?x=1"');
});
