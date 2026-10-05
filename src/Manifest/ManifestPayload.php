<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Manifest;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Servers\ServerDefinition;
use SytxLabs\LaravelWebMcp\Support\Settings;

/**
 * The document the browser runtime consumes, both embedded in the page and returned by the manifest endpoint.
 * Tool definitions plus the endpoints and conventions needed to run them. It contains no class names, file paths or secrets; the only token in it is the CSRF token the page could read anyway.
 */
final class ManifestPayload
{
    public const int VERSION = 1;

    public function __construct(private readonly Settings $settings)
    {
    }

    /** @return array<string, mixed> */
    public function build(ServerDefinition $server, Manifest $manifest): array
    {
        return [
            'version' => self::VERSION,
            'server' => $server->slug,
            'locale' => app()->getLocale(),
            'csrf' => $this->csrf(),
            'errors' => $this->settings->string('errors.mode', 'result') === 'reject' ? 'reject' : 'result',
            'header' => $this->settings->string('header', 'X-WebMCP'),
            'encodeVariables' => $this->settings->bool('resources.encode_variables', false),
            'confirmation' => ['serverEnforced' => $this->settings->bool('confirmation.server_enforced', false), 'header' => $this->settings->string('confirmation.header', 'X-WebMCP-Confirmation')],
            'endpoints' => $this->endpoints($server),
            'tools' => array_map(fn (ToolDefinition $tool): array => $this->tool($tool), $manifest->tools),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tool(ToolDefinition $tool): array
    {
        $data = $tool->toArray();

        if ($tool->mode === WebMcpMode::Bridge) {
            $data['bridge'] = match ($tool->kind) {
                ToolDefinition::KIND_TOOL => ['method' => 'tools/call', 'name' => $tool->mcpName],
                ToolDefinition::KIND_GENERIC_RESOURCE => ['method' => 'resources/read', 'generic' => true],
                default => $tool->uriTemplate !== null ? ['method' => 'resources/read', 'template' => $tool->uriTemplate] : ['method' => 'resources/read', 'uri' => $tool->uri],
            };
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function endpoints(ServerDefinition $server): array
    {
        $endpoints = [];

        if (Route::has('webmcp.manifest')) {
            $endpoints['manifest'] = route('webmcp.manifest', ['server' => $server->slug], false);
            $endpoints['tools'] = str_replace('__name__', '{name}', route('webmcp.tools', ['server' => $server->slug, 'tool' => '__name__'], false));
            $endpoints['resources'] = str_replace('__name__', '{name}', route('webmcp.resources', ['server' => $server->slug, 'resource' => '__name__'], false));
        }
        if ($server->endpoint !== null) {
            $path = parse_url($server->endpoint, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $endpoints['bridge'] = '/'.ltrim($path, '/');
            }
        }

        return $endpoints;
    }

    private function csrf(): ?string
    {
        try {
            return csrf_token();
        } catch (RuntimeException) {
            return null;
        }
    }
}
