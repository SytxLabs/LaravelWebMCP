<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\View;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use SytxLabs\LaravelWebMcp\Manifest\ManifestPayload;
use SytxLabs\LaravelWebMcp\Servers\ServerDefinition;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;
use SytxLabs\LaravelWebMcp\Support\Settings;
use SytxLabs\LaravelWebMcp\WebMcpManager;

/**
 * Renders the JSON manifest(s) for the current user plus the runtime tag. Behind both `@webmcp(...)` and `<x-webmcp::tools />`.
 */
final class Renderer
{
    public const string VIEW = 'webmcp::tools';

    private const int JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(private readonly ServerRegistry $registry, private readonly WebMcpManager $manager, private readonly ManifestPayload $payloads, private readonly NonceResolver $nonce, private readonly Settings $settings, private readonly ViewFactory $views)
    {
    }

    /**
     * @param class-string|list<string>|string|null $servers slug(s) or server class(es); null = every registered server
     */
    public function render(array|string|null $servers = null, bool $script = true): HtmlString
    {
        return new HtmlString($this->views->make(self::VIEW, $this->data($servers, $script))->render());
    }

    /**
     * @param class-string|list<string>|string|null $servers
     *
     * @return array{manifests: list<array{slug: string, json: string}>, nonce: string|null, script: bool, src: string}
     */
    public function data(array|string|null $servers = null, bool $script = true): array
    {
        $definitions = $this->definitions($servers);

        $manifests = $this->manager->manifests(array_map(static fn (ServerDefinition $d): string => $d->slug, $definitions));
        $out = [];
        $limit = $this->settings->int('limits.max_tools_per_page', 128);
        $budget = $limit;
        $dropped = [];

        foreach ($definitions as $definition) {
            $payload = $this->payloads->build($definition, $manifests[$definition->slug]);
            if ($limit > 0) {
                $tools = is_array($payload['tools']) ? $payload['tools'] : [];
                $payload['tools'] = array_slice($tools, 0, max(0, $budget));
                $dropped = [...$dropped, ...array_map(fn (mixed $t): string => is_array($t) && is_string($t['name'] ?? null) ? $t['name'] : '?', array_slice($tools, max(0, $budget)))];
                $budget -= count($payload['tools']);
            }
            $out[] = ['slug' => $definition->slug, 'json' => json_encode($payload, self::JSON_FLAGS)];
        }

        if ($dropped !== []) {
            Log::warning('WebMCP: page tool limit exceeded, dropping tools.', ['limit' => $limit, 'dropped' => $dropped]);
        }

        return ['manifests' => $out, 'nonce' => $this->nonce->resolve(), 'script' => $script && $this->settings->bool('assets.script', true), 'src' => $this->runtimeUrl()];
    }

    /**
     * @param class-string|list<string>|string|null $servers
     *
     * @return list<ServerDefinition>
     */
    private function definitions(array|string|null $servers): array
    {
        if ($servers === null) {
            return array_values(array_filter($this->registry->all(), static fn (ServerDefinition $d): bool => $d->enabled));
        }

        return array_values(array_map(fn (string $s): ServerDefinition => $this->registry->resolve($s), Arr::wrap($servers)));
    }

    private function runtimeUrl(): string
    {
        $configured = $this->settings->string('assets.url', '');
        if ($configured !== '') {
            return $configured;
        }
        $file = public_path('vendor/webmcp/webmcp.js');

        return asset('vendor/webmcp/webmcp.js').(is_file($file) ? '?v='.filemtime($file) : '');
    }
}
