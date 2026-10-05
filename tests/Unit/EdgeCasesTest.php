<?php

declare(strict_types=1);

use SytxLabs\LaravelWebMcp\Attributes\WebMcp;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpAction;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;
use SytxLabs\LaravelWebMcp\Exceptions\UnknownWebMcpServerException;
use SytxLabs\LaravelWebMcp\Execution\InMemoryTransport;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;
use SytxLabs\LaravelWebMcp\Support\OriginAllowlist;
use SytxLabs\LaravelWebMcp\Tests\Fixtures\BasicServer;
use SytxLabs\LaravelWebMcp\Tests\Fixtures\CatalogServer;
use SytxLabs\LaravelWebMcp\Tests\Fixtures\ServerDefaultsServer;

it('rejects invalid #[WebMcp] arguments', function (array $args): void {
    new WebMcp(...$args);
})->throws(InvalidArgumentException::class)->with([
    'name' => [['name' => 'bad name!']],
    'prefix' => [['prefix' => 'bad prefix!']],
    'pattern' => [['variablePattern' => '/(unclosed']],
]);

it('rejects invalid #[WebMcpAction] arguments', function (array $args): void {
    new WebMcpAction(...$args);
})->throws(InvalidArgumentException::class)->with([
    'empty description' => [['description' => '']],
    'name' => [['description' => 'x', 'name' => 'bad name!']],
    'readonly and consequential' => [['description' => 'x', 'readOnly' => true, 'consequential' => true]],
]);

it('normalizes and validates origins', function (): void {
    expect(OriginAllowlist::normalize('not a url'))->toBeNull()
        ->and(OriginAllowlist::normalize('https://example.com/path'))->toBeNull()
        ->and(OriginAllowlist::normalize('https://user@example.com'))->toBeNull()
        ->and(OriginAllowlist::normalize('http://example.com'))->toBeNull()
        ->and(OriginAllowlist::normalize('HTTP://localhost:8080/'))->toBe('http://localhost:8080')
        ->and(OriginAllowlist::normalize('https://Example.com'))->toBe('https://example.com');

    expect(OriginAllowlist::assertAllowed(['https://a.test', 'https://a.test'], ['https://a.test'], 'src'))->toBe(['https://a.test']);
    expect(fn () => OriginAllowlist::assertAllowed(['http://evil.com'], ['https://a.test'], 'src'))->toThrow(InvalidWebMcpConfigurationException::class);
    expect(fn () => OriginAllowlist::assertAllowed(['https://b.test'], ['https://a.test'], 'src'))->toThrow(InvalidWebMcpConfigurationException::class);
});

it('collects streamed transport messages and ignores non-string parts', function (): void {
    $transport = new InMemoryTransport();
    $transport->onReceive(function (string $raw) use ($transport): void {
        $transport->send("echo:{$raw}");
        $transport->stream(fn () => ['a', 1, new class implements Stringable {
            public function __toString(): string
            {
                return 'b';
            }
        }]);
        $transport->stream(fn () => null);
    });

    expect($transport->dispatch('hi'))->toBe(['echo:hi', 'a', 'b'])
        ->and($transport->run())->toBe(['echo:hi', 'a', 'b']);
    expect((new InMemoryTransport())->dispatch('x'))->toBe([]);
});

it('validates server registrations', function (): void {
    $registry = new ServerRegistry();

    expect(fn () => $registry->register('Bad Slug', stdClass::class))->toThrow(InvalidArgumentException::class);
    expect(fn () => $registry->register('ok', stdClass::class))->toThrow(InvalidArgumentException::class);
    expect(fn () => $registry->registerMany(['ok' => ['class' => stdClass::class]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $registry->get('missing'))->toThrow(UnknownWebMcpServerException::class);
    expect(fn () => $registry->resolve('Missing\Server'))->toThrow(UnknownWebMcpServerException::class);
});

it('registers servers from config arrays and resolves them by class', function (): void {
    $registry = new ServerRegistry();
    $registry->registerMany([
        'plain' => BasicServer::class,
        'full' => ['class' => CatalogServer::class, 'endpoint' => '/mcp/x', 'mode' => 'bridge', 'prefix' => 'shop', 'enabled' => false],
        'loose' => ['class' => ServerDefaultsServer::class, 'endpoint' => 5, 'mode' => 7, 'prefix' => 9],
    ]);

    $full = $registry->get('full');
    expect($registry->get('plain')->endpoint)->toBeNull()
        ->and($full->endpoint)->toBe('/mcp/x')
        ->and($full->mode)->toBe(WebMcpMode::Bridge)
        ->and($full->prefix)->toBe('shop')
        ->and($full->enabled)->toBeFalse()
        ->and($registry->get('loose')->mode)->toBeNull()
        ->and($registry->resolve('\\'.CatalogServer::class))->toBe($full)
        ->and($registry->resolve('plain'))->toBe($registry->get('plain'));
});

it('reports package information in artisan about', function (): void {
    config()->set('webmcp.limits.max_tools_per_page', 0);
    config()->set('webmcp.routes.enabled', false);
    $this->artisan('about')->expectsOutputToContain('SytxLabs WebMCP')->assertSuccessful();

    config()->set('webmcp.limits.max_tools_per_page', 10);
    config()->set('webmcp.routes.enabled', true);
    app(ServerRegistry::class)->register('basic', BasicServer::class);
    $this->artisan('about --json')->assertSuccessful();
});
