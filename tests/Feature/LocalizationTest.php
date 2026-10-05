<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;

beforeEach(function () {
    WebMcp::server('i18n', Fixtures\TranslatedServer::class);
    WebMcp::server('shop', Fixtures\FormServer::class);

    app('translator')->addLines([
        'webmcp-test.tool.title' => 'Wetter',
        'webmcp-test.tool.description' => 'Zeigt das aktuelle Wetter.',
        'webmcp-test.param.city' => 'Name der Stadt',
        'webmcp-test.resource.description' => 'Eine übersetzte Ressource.',
    ], 'de');

    app('translator')->addLines([
        'webmcp-test.tool.title' => 'Weather',
        'webmcp-test.tool.description' => 'Shows the current weather.',
        'webmcp-test.param.city' => 'City name',
        'webmcp-test.resource.description' => 'A translated resource.',
    ], 'en');
});

it('renders tool title, description and parameter descriptions in the current locale', function () {
    $en = WebMcp::manifest('i18n')->tool('translated-tool');

    app()->setLocale('de');
    $de = WebMcp::manifest('i18n')->tool('translated-tool');

    expect($en->title)->toBe('Weather')
        ->and($en->description)->toBe('Shows the current weather.')
        ->and($en->inputSchema['properties']['city']['description'])->toBe('City name')
        ->and($de->title)->toBe('Wetter')
        ->and($de->description)->toBe('Zeigt das aktuelle Wetter.')
        ->and($de->inputSchema['properties']['city']['description'])->toBe('Name der Stadt');
});

it('never translates tool names', function () {
    app()->setLocale('de');

    expect(WebMcp::manifest('i18n')->toolNames())->toBe(['translated-tool', 'read-translated-resource']);
});

it('keeps text that is not a translation key as written', function () {
    app()->setLocale('de');

    expect(WebMcp::manifest('i18n')->tool('translated-tool')->inputSchema['properties']['plain']['description'])->toBe('No translation exists for this text.');
});

it('falls back to the written key when the locale has no translation', function () {
    app()->setLocale('fr');
    app()->setFallbackLocale('en');

    expect(WebMcp::manifest('i18n')->tool('translated-tool')->description)->toBe('Shows the current weather.');
});

it('translates the sentences the package generates for resource tools', function () {
    $en = WebMcp::manifest('i18n')->tool('read-translated-resource')->description;

    app()->setLocale('de');
    $de = WebMcp::manifest('i18n')->tool('read-translated-resource')->description;

    expect($en)->toBe('A translated resource. Reads the resource file://resources/translated. Audience: user.')
        ->and($de)->toBe('Eine übersetzte Ressource. Liest die Ressource file://resources/translated. Zielgruppe: user.');
});

it('translates the URI template variable descriptions', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);
    app()->setLocale('de');

    $property = WebMcp::manifest('basic')->tool('read-user-doc-resource')->inputSchema['properties']['userId'];

    expect($property['description'])->toBe('Wert für {userId} im URI-Template file://users/{userId}/docs/{docId}.');
});

it('translates the generic reader texts', function () {
    config(['webmcp.resources.generic_reader' => true]);
    app()->setLocale('de');

    $reader = WebMcp::manifest('i18n')->tool('read-resource');

    expect($reader->title)->toBe('Ressource lesen')
        ->and($reader->description)->toStartWith('Liest eine der freigegebenen Ressourcen')
        ->and($reader->inputSchema['properties']['uri']['description'])->toBe('Die zu lesende Ressourcen-URI.');
});

it('renders the embedded manifest in the current locale and says which', function () {
    app()->setLocale('de');

    $html = Blade::render('@webmcp("i18n")');
    preg_match('#data-webmcp-manifest[^>]*>(.*?)</script>#s', $html, $m);
    $payload = json_decode($m[1], true);

    expect($payload['locale'])->toBe('de')->and($payload['tools'][0]['title'])->toBe('Wetter');
});

it('localizes the label of the declarative form button', function () {
    $en = Blade::render('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\SearchProductsTool::class" method="get" />');

    app()->setLocale('de');
    $de = Blade::render('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\SearchProductsTool::class" method="get" />');
    $custom = Blade::render('<x-webmcp::form :tool="\\SytxLabs\\LaravelWebMcp\\Tests\\Fixtures\\SearchProductsTool::class" method="get" submit="Suchen" />');

    expect($en)->toContain('>Submit</button>')
        ->and($de)->toContain('>Absenden</button>')
        ->and($custom)->toContain('>Suchen</button>');
});

it('lets the application override the package messages', function () {
    app('translator')->addLines(['messages.reads_resource' => 'Custom: :uri'], 'en', 'webmcp');

    expect(WebMcp::manifest('i18n')->tool('read-translated-resource')->description)->toContain('Custom: file://resources/translated');
});
