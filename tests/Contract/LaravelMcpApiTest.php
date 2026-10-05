<?php

declare(strict_types=1);

// Guards every laravel/mcp touchpoint this package relies on. If a laravel/mcp update changes one
// of them, CI fails here instead of silently exposing the wrong tools in production.

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Primitive;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\ToolSearch;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Support\UriTemplate;

it('still keeps tools, resources and prompts in protected Server properties', function () {
    foreach (['tools', 'resources', 'prompts'] as $property) {
        $reflection = new ReflectionProperty(Server::class, $property);

        expect($reflection->isProtected())->toBeTrue()
            ->and((string) $reflection->getType())->toBe('array');
    }
});

it('still supports starting a server with a no-op transport', function () {
    expect(new FakeTransporter())->toBeInstanceOf(Transport::class)
        ->and(method_exists(Server::class, 'start'))->toBeTrue();
});

it('still exposes shouldRegister evaluation as eligibleForRegistration()', function () {
    expect(method_exists(Primitive::class, 'eligibleForRegistration'))->toBeTrue();
});

it('still maps tool annotations to the keys this package translates', function () {
    expect((new IsReadOnly())->key())->toBe('readOnlyHint')
        ->and((new IsDestructive())->key())->toBe('destructiveHint');
});

it('still uses ToolSearch::class as the catalog key', function () {
    expect(class_exists(ToolSearch::class))->toBeTrue();
});

it('still exposes URI template variable names', function () {
    $template = new UriTemplate('file://a/{one}/b/{two}');

    expect($template->variableNames())->toBe(['one', 'two'])
        ->and(interface_exists(HasUriTemplate::class))->toBeTrue();
});
