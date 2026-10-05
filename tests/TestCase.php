<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Tests;

use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SytxLabs\LaravelWebMcp\Support\AttributeResolver;
use SytxLabs\LaravelWebMcp\WebMcpServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            McpServiceProvider::class,
            class_exists(LivewireServiceProvider::class) ? LivewireServiceProvider::class : null,
            WebMcpServiceProvider::class,
        ]));
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('webmcp.allowed_origins', ['https://chat.example.com']);

        // Deterministic drivers: the tests must not depend on a database or files in the skeleton.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        AttributeResolver::flush();
    }
}
