<?php

declare(strict_types=1);

use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Manifest\ExclusionReason;
use SytxLabs\LaravelWebMcp\Manifest\ToolDefinition;
use SytxLabs\LaravelWebMcp\Tests\Fixtures;

it('turns a static resource into a read-<name> tool without input', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $tool = WebMcp::manifest('basic')->tool('read-settings-resource');

    expect($tool)->not->toBeNull()
        ->and($tool->kind)->toBe(ToolDefinition::KIND_RESOURCE)
        ->and($tool->uri)->toBe('file://resources/settings')
        ->and(json_encode($tool->inputSchema))->toBe('{"type":"object","properties":{},"required":[]}')
        ->and($tool->description)->toContain('Reads the resource file://resources/settings')
        ->and($tool->description)->toContain('Audience: user.')
        ->and($tool->description)->toContain('Priority: 0.8.');
});

it('marks resource tools read-only and untrusted, and never confirm', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $tool = WebMcp::manifest('basic')->tool('read-settings-resource');

    expect($tool->annotations)->toBe(['readOnlyHint' => true, 'untrustedContentHint' => true])
        ->and($tool->confirm)->toBeFalse();
});

it('builds the input schema of a template resource from the URI template variables', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $tool = WebMcp::manifest('basic')->tool('read-user-doc-resource');

    expect($tool->variableNames)->toBe(['userId', 'docId'])
        ->and($tool->inputSchema['required'])->toBe(['userId', 'docId'])
        ->and($tool->inputSchema['properties']['userId']['type'])->toBe('string')
        ->and($tool->description)->toContain('file://users/{userId}/docs/{docId}');
});

it('excludes template resources that cannot authorize a concrete read', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $manifest = WebMcp::manifest('basic');

    expect($manifest->tool('read-open-template-resource'))->toBeNull();

    $reason = collect($manifest->exclusions)->firstWhere('source', Fixtures\OpenTemplateResource::class)->reason;
    expect($reason)->toBe(ExclusionReason::TemplateWithoutAuthorization);
});

it('can relax the template authorization requirement via config', function () {
    config(['webmcp.resources.require_authorization' => false]);
    WebMcp::server('basic', Fixtures\BasicServer::class);

    expect(WebMcp::manifest('basic')->tool('read-open-template-resource'))->not->toBeNull();
});

it('rejects confirm on resources', function () {
    WebMcp::server('bad', Fixtures\BadResourceServer::class);

    WebMcp::manifest('bad');
})->throws(InvalidWebMcpConfigurationException::class, 'always read-only');

it('can omit resource annotations and the untrusted hint through config', function () {
    config(['webmcp.resources.append_annotations' => false, 'webmcp.resources.untrusted' => false]);
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $tool = WebMcp::manifest('basic')->tool('read-settings-resource');

    expect($tool->description)->not->toContain('Audience')
        ->and($tool->annotations)->toBe(['readOnlyHint' => true]);
});

it('adds a generic read-resource tool with an allowlist only when enabled', function () {
    WebMcp::server('basic', Fixtures\BasicServer::class);

    expect(WebMcp::manifest('basic')->tool('read-resource'))->toBeNull();

    config(['webmcp.resources.generic_reader' => true]);

    $reader = WebMcp::manifest('basic')->tool('read-resource');

    expect($reader->kind)->toBe(ToolDefinition::KIND_GENERIC_RESOURCE)
        ->and($reader->allowedUris)->toBe(['file://resources/settings', 'file://users/{userId}/docs/{docId}'])
        ->and($reader->mode)->toBe(WebMcpMode::Session)
        ->and($reader->inputSchema['required'])->toBe(['uri']);
});

it('keeps the allowlist and class names out of the browser manifest', function () {
    config(['webmcp.resources.generic_reader' => true]);
    WebMcp::server('basic', Fixtures\BasicServer::class);

    $data = WebMcp::manifest('basic')->tool('read-resource')->toArray();

    expect($data)->not->toHaveKey('allowedUris')
        ->and($data)->not->toHaveKey('source');
});
