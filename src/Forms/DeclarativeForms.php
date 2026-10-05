<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Forms;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use JsonException;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;
use SytxLabs\LaravelWebMcp\Manifest\ToolDefinition;
use SytxLabs\LaravelWebMcp\Servers\ServerDefinition;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;
use SytxLabs\LaravelWebMcp\Support\Settings;
use SytxLabs\LaravelWebMcp\WebMcpManager;

/**
 * Declarative WebMCP forms (declarative-api-explainer.md): a `<form>` carrying `toolname`, `tooldescription` and optionally `toolautosubmit`, whose controls carry `name` and `toolparamdescription`.
 * The browser derives the tool and its input schema from the form itself.
 *
 * The source of truth stays the exposed laravel/mcp tool: name, description, field descriptions and the auto-submit decision come from it.
 * Auto-submit is only on for read-only tools (or when set explicitly, and never for consequential tools unless config forms.allow_autosubmit_consequential allows it).
 */
final readonly class DeclarativeForms
{
    public function __construct(private ServerRegistry $servers, private WebMcpManager $manager, private FormFieldMapper $mapper, private Settings $settings)
    {
    }

    /**
     * Everything needed to render a form for a tool.
     *
     * @param class-string $toolClass
     * @param list<string> $except
     *
     * @return array{definition: ToolDefinition, fields: list<FormField>, types: array<string, string>, endpoint: string, autosubmit: bool}
     */
    public function prepare(string $toolClass, ?string $server = null, ?bool $autosubmit = null, array $except = []): array
    {
        $definition = $this->definition($toolClass, $server);
        $fields = $this->mapper->map($definition->name, $definition->inputSchema, $except);

        $types = [];

        foreach ($fields as $field) {
            $types[$field->name] = $field->type;
        }

        return [
            'definition' => $definition,
            'fields' => $fields,
            'types' => $types,
            'endpoint' => $this->endpoint($definition),
            'autosubmit' => $this->autosubmit($definition, $autosubmit),
        ];
    }

    /**
     * Attribute string for an existing `<form>`: `@webmcpForm(SearchProductsTool::class)`.
     *
     * @param class-string $toolClass
     *
     * @throws JsonException
     */
    public function attributes(string $toolClass, ?string $server = null, ?bool $autosubmit = null): HtmlString
    {
        $definition = $this->definition($toolClass, $server);
        $types = collect($this->mapper->map($definition->name, $definition->inputSchema))->mapWithKeys(fn ($field) => [$field->name => $field->type])->toJson();
        $html = [];
        foreach (['toolname' => $definition->name, 'tooldescription' => $definition->description, 'data-webmcp-endpoint' => $this->endpoint($definition), 'data-webmcp-types' => $types] as $name => $value) {
            $html[] = $name.'="'.e($value).'"';
        }
        if ($this->autosubmit($definition, $autosubmit)) {
            $html[] = 'toolautosubmit';
        }
        if ($definition->confirm) {
            $html[] = 'data-webmcp-confirm';
        }

        return new HtmlString(implode(' ', $html));
    }

    /**
     * `toolparamdescription` for an existing control: `@webmcpParam(SearchProductsTool::class, 'query')`.
     *
     * @param class-string $toolClass
     */
    public function param(string $toolClass, string $property, ?string $server = null): HtmlString
    {
        $definition = $this->definition($toolClass, $server);
        $properties = $definition->inputSchema['properties'] ?? [];
        $properties = is_object($properties) ? (array) $properties : $properties;
        $description = is_array($properties) && is_array($properties[$property] ?? null) ? ($properties[$property]['description'] ?? null) : null;

        if (!is_array($properties) || !array_key_exists($property, $properties)) {
            throw new InvalidWebMcpConfigurationException("Tool [{$definition->name}] has no input property [{$property}].");
        }

        return new HtmlString(is_string($description) && $description !== '' ? 'toolparamdescription="'.e($description).'"' : '');
    }

    /**
     * @param class-string $toolClass
     */
    public function definition(string $toolClass, ?string $server = null): ToolDefinition
    {
        $slugs = $server !== null ? [$this->servers->resolve($server)->slug] : array_keys($this->servers->all());
        $reasons = [];

        foreach ($slugs as $slug) {
            $manifest = $this->manager->manifest($slug);

            foreach ($manifest->tools as $tool) {
                if ($tool->kind === ToolDefinition::KIND_TOOL && ltrim($tool->source, '\\') === ltrim($toolClass, '\\')) {
                    return $this->assertSession($tool);
                }
            }

            foreach ($manifest->exclusions as $exclusion) {
                if (ltrim($exclusion->source, '\\') === ltrim($toolClass, '\\')) {
                    $reasons[] = "Excluded on server [{$slug}]: {$exclusion->reason->value}.";
                }
            }
        }

        throw new InvalidWebMcpConfigurationException(
            "[{$toolClass}] is not an exposed WebMCP tool of ".($server !== null ? "server [{$server}]" : 'any registered server').'.'
            .($reasons === [] ? ' Register its server and add #[WebMcp] to the tool.' : ' '.implode(' ', $reasons))
        );
    }

    private function assertSession(ToolDefinition $tool): ToolDefinition
    {
        if ($tool->mode !== WebMcpMode::Session) {
            throw new InvalidWebMcpConfigurationException("Declarative forms submit through the Session routes, but tool [{$tool->name}] is in Bridge mode. Use #[WebMcp(mode: WebMcpMode::Session)].");
        }

        return $tool;
    }

    private function endpoint(ToolDefinition $definition): string
    {
        if (!Route::has('webmcp.tools')) {
            throw new InvalidWebMcpConfigurationException('Declarative forms need the Session routes (config webmcp.routes.enabled).');
        }

        return route('webmcp.tools', ['server' => $definition->server, 'tool' => $definition->name], false);
    }

    private function autosubmit(ToolDefinition $definition, ?bool $explicit): bool
    {
        if ($explicit === null) {
            return ($definition->annotations['readOnlyHint'] ?? false) === true;
        }
        if ($explicit && ($definition->annotations['consequentialHint'] ?? false) === true && !$this->settings->bool('forms.allow_autosubmit_consequential', false)) {
            throw new InvalidWebMcpConfigurationException(
                "Tool [{$definition->name}] is consequential: auto-submit would let an agent run it without the user checking the form. "
                .'Remove autosubmit, or set webmcp.forms.allow_autosubmit_consequential if you really mean it.'
            );
        }

        return $explicit;
    }

    public function server(ToolDefinition $definition): ServerDefinition
    {
        return $this->servers->get($definition->server);
    }
}
