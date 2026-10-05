<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Manifest;

use Illuminate\Contracts\Container\Container;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ExecuteTools;
use Laravel\Mcp\Server\Tools\SearchTools;
use Laravel\Mcp\Server\Tools\ToolSearch;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Server\Ui\Enums\Visibility;
use ReflectionProperty;
use stdClass;
use SytxLabs\LaravelWebMcp\Attributes\WebMcp;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Contracts\AuthorizesWebMcpRead;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpTargetException;
use SytxLabs\LaravelWebMcp\Servers\ServerDefinition;
use SytxLabs\LaravelWebMcp\Support\AttributeResolver;
use SytxLabs\LaravelWebMcp\Support\Localizer;
use SytxLabs\LaravelWebMcp\Support\OriginAllowlist;
use SytxLabs\LaravelWebMcp\Support\Settings;
use SytxLabs\LaravelWebMcp\Support\ToolNameValidator;

/**
 * Builds the WebMCP manifest of one laravel/mcp server for the current request/user.
 *
 * The opt-in decision (attributes, types) is made by reflection WITHOUT instantiating anything; only exposed classes are created, via the container, and then asked for shouldRegister().
 *
 * Reflection on the protected Server::$tools / $resources / $prompts properties is the single non-public touchpoint with laravel/mcp (needed for ToolSearch catalog members, which the public ServerContext does not expose).
 */
final readonly class ManifestCompiler
{
    public function __construct(private Container $container, private Settings $settings, private Localizer $localizer)
    {
    }

    public function compile(ServerDefinition $definition, ?NameRegistry $names = null): Manifest
    {
        if (!$definition->enabled || !$this->settings->bool('enabled', true)) {
            return new Manifest($definition->slug, []);
        }

        $names ??= $this->container->make(NameRegistry::class);
        $serverAttribute = AttributeResolver::webMcp($definition->class);
        $server = $this->makeServer($definition);

        $tools = [];
        $exclusions = [];

        $entries = [];
        foreach ($this->property($server, 'tools') as $key => $entry) {
            if ($key === ToolSearch::class) {
                if (!is_array($entry)) {
                    throw new InvalidWebMcpConfigurationException('The ToolSearch::class entry must contain an array of tools.');
                }
                foreach ($entry as $inner) {
                    $entries[] = $inner;
                }

                continue;
            }
            if (is_array($entry)) {
                throw new InvalidWebMcpConfigurationException('Tool groups must use ToolSearch::class as their key.');
            }
            $entries[] = $entry;
        }
        foreach ($entries as $entry) {
            $this->processTool($entry, $definition, $serverAttribute, $names, $tools, $exclusions);
        }
        $resourceTools = [];
        foreach ($this->property($server, 'resources') as $entry) {
            $this->processResource($entry, $definition, $serverAttribute, $names, $resourceTools, $exclusions);
        }
        foreach ($this->property($server, 'prompts') as $entry) {
            $this->processPrompt($entry, $definition, $exclusions);
        }
        $tools = [...$tools, ...$resourceTools];
        if ($resourceTools !== [] && $this->settings->bool('resources.generic_reader', false)) {
            $tools[] = $this->genericReader($definition, $serverAttribute, $names, $resourceTools);
        }
        [$tools, $exclusions] = $this->applyLimit($definition, $tools, $exclusions);

        return new Manifest($definition->slug, $tools, $exclusions, $this->cacheable($serverAttribute, $tools, $exclusions));
    }

    /**
     * A manifest is not cached when the server or any class that took part (exposed OR excluded by shouldRegister) opted out with #[WebMcp(cache: false)]: its decision depends on state the cache key does not know.
     *
     * @param list<ToolDefinition> $tools
     * @param list<Exclusion> $exclusions
     */
    private function cacheable(?WebMcp $serverAttribute, array $tools, array $exclusions): bool
    {
        if ($serverAttribute !== null && $serverAttribute->cache === false) {
            return false;
        }
        foreach ([...array_map(static fn (ToolDefinition $tool): string => $tool->source, $tools), ...array_map(static fn (Exclusion $exclusion): string => $exclusion->source, $exclusions)] as $source) {
            if (AttributeResolver::webMcp($source)?->cache === false) {
                return false;
            }
        }

        return true;
    }

    private function makeServer(ServerDefinition $definition): Server
    {
        $server = $this->container->make($definition->class, ['transport' => new FakeTransporter()]);
        if (!$server instanceof Server) {
            throw new InvalidWebMcpConfigurationException("[{$definition->class}] did not resolve to a laravel/mcp server.");
        }
        $server->start();

        return $server;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function property(Server $server, string $name): array
    {
        $value = (new ReflectionProperty($server, $name))->getValue($server);

        return is_array($value) ? $value : [];
    }

    /**
     * @return class-string
     */
    private function classOf(mixed $entry): string
    {
        if (is_object($entry)) {
            return $entry::class;
        }
        if (is_string($entry) && class_exists($entry)) {
            return $entry;
        }
        throw new InvalidWebMcpConfigurationException('Server entries must be class names or instances.');
    }

    /**
     * @param list<ToolDefinition> $tools
     * @param list<Exclusion> $exclusions
     */
    private function processTool(mixed $entry, ServerDefinition $definition, ?WebMcp $serverAttribute, NameRegistry $names, array &$tools, array &$exclusions): void
    {
        $class = $this->classOf($entry);

        if (is_a($class, SearchTools::class, true) || is_a($class, ExecuteTools::class, true)) {
            $exclusions[] = new Exclusion($definition->slug, $class, ExclusionReason::ToolSearchMeta);

            return;
        }

        $own = AttributeResolver::webMcp($class);
        if ($own === null && !$this->exposesAll($serverAttribute)) {
            $exclusions[] = new Exclusion($definition->slug, $class, ExclusionReason::MissingAttribute);

            return;
        }
        $this->assertNotServerOnly($own, $class);
        $rendersApp = AttributeResolver::find($class, RendersApp::class);
        if ($rendersApp instanceof RendersApp && !in_array(Visibility::Model, $rendersApp->visibility, true) && ($own === null || !$own->allowAppOnly)) {
            $exclusions[] = new Exclusion($definition->slug, $class, ExclusionReason::AppOnly);

            return;
        }
        $tool = is_object($entry) ? $entry : $this->container->make($class);
        if (!$tool instanceof Tool) {
            throw new InvalidWebMcpConfigurationException("[{$class}] is not a laravel/mcp tool.");
        }
        if (!$tool->eligibleForRegistration()) {
            $exclusions[] = new Exclusion($definition->slug, $class, ExclusionReason::ShouldRegister);

            return;
        }

        $options = $this->resolveOptions($own, $serverAttribute, $definition, $class);

        $name = $this->finalName($options->name ?? $tool->name(), $definition, $serverAttribute, $class);
        $names->claim($name, ToolDefinition::claimLabel($definition->slug, $class, ToolDefinition::KIND_TOOL));

        $annotations = $tool->annotations();
        $flags = [];
        if (($annotations['readOnlyHint'] ?? false) === true) {
            $flags['readOnlyHint'] = true;
        }
        if (($annotations['destructiveHint'] ?? false) === true) {
            $flags['consequentialHint'] = true;
        }
        if ($options->untrusted) {
            $flags['untrustedContentHint'] = true;
        }
        if ($options->debugging) {
            $flags['debugging'] = true;
        }
        $title = $this->limit($this->localizer->text($tool->title()), 'limits.max_title');
        /** @var array<string, mixed> $schema */
        $schema = $this->localizer->schema($this->normalizeSchema(JsonSchemaFactory::object($tool->schema(...))->toArray()));
        if ($this->settings->bool('features.output_schema', false)) {
            $output = JsonSchemaFactory::object($tool->outputSchema(...))->toArray();
            if (isset($output['properties'])) {
                $schema['outputSchema'] = $output;
            }
        }
        $tools[] = new ToolDefinition($definition->slug, $name, $title, $this->description($this->localizer->text($tool->description()), $title), $schema, $flags, $options->exposedTo, $options->mode, $options->confirm ?? isset($flags['consequentialHint']), ToolDefinition::KIND_TOOL, source: $class, mcpName: $tool->name());
    }

    /**
     * @param list<ToolDefinition> $tools
     * @param list<Exclusion> $exclusions
     */
    private function processResource(mixed $entry, ServerDefinition $definition, ?WebMcp $serverAttribute, NameRegistry $names, array &$tools, array &$exclusions): void
    {
        $class = $this->classOf($entry);

        if (is_a($class, AppResource::class, true)) {
            $exclusions[] = new Exclusion($definition->slug, $class, ExclusionReason::AppResource);

            return;
        }

        $own = AttributeResolver::webMcp($class);

        if ($own === null && !$this->exposesAll($serverAttribute)) {
            $exclusions[] = new Exclusion($definition->slug, $class, ExclusionReason::MissingAttribute);

            return;
        }

        $this->assertNotServerOnly($own, $class);

        if ($own !== null && $own->confirm === true) {
            throw new InvalidWebMcpConfigurationException(
                "#[WebMcp(confirm: true)] on resource [{$class}] is not allowed: resource tools are always read-only."
            );
        }

        if (is_a($class, HasUriTemplate::class, true) && !is_a($class, AuthorizesWebMcpRead::class, true) && $this->settings->bool('resources.require_authorization', true)) {
            $exclusions[] = new Exclusion($definition->slug, $class, ExclusionReason::TemplateWithoutAuthorization, 'Implement '.AuthorizesWebMcpRead::class.' or set webmcp.resources.require_authorization=false.');

            return;
        }

        $resource = is_object($entry) ? $entry : $this->container->make($class);

        if (!$resource instanceof Resource) {
            throw new InvalidWebMcpConfigurationException("[{$class}] is not a laravel/mcp resource.");
        }

        if (!$resource->eligibleForRegistration()) {
            $exclusions[] = new Exclusion($definition->slug, $class, ExclusionReason::ShouldRegister);

            return;
        }

        $options = $this->resolveOptions($own, $serverAttribute, $definition, $class);

        $name = $this->finalName($options->name ?? 'read-'.$resource->name(), $definition, $serverAttribute, $class);
        $names->claim($name, ToolDefinition::claimLabel($definition->slug, $class, ToolDefinition::KIND_RESOURCE));

        $variables = [];
        $template = null;
        $uri = null;

        if ($resource instanceof HasUriTemplate) {
            $template = (string) $resource->uriTemplate();
            $variables = $resource->uriTemplate()->variableNames();
        } else {
            $uri = $resource->uri();
        }

        $properties = [];

        foreach ($variables as $variable) {
            $properties[$variable] = ['type' => 'string', 'description' => $this->localizer->message('variable', ['name' => $variable, 'template' => (string) $template])];
        }

        $schema = ['type' => 'object', 'properties' => $properties === [] ? new stdClass() : $properties, 'required' => $variables];
        $flags = ['readOnlyHint' => true];
        if ($options->untrusted || $this->settings->bool('resources.untrusted', true)) {
            $flags['untrustedContentHint'] = true;
        }
        if ($options->debugging) {
            $flags['debugging'] = true;
        }
        $title = $this->limit($this->localizer->text($resource->title()), 'limits.max_title');
        $description = $this->localizer->text($resource->description()).' '.($template !== null ? $this->localizer->message('reads_template', ['template' => $template]) : $this->localizer->message('reads_resource', ['uri' => (string) $uri]));
        if ($this->settings->bool('resources.append_annotations', true)) {
            $description .= $this->annotationSentence($resource->annotations());
        }
        $tools[] = new ToolDefinition($definition->slug, $name, $title, $this->description($description, $title), $schema, $flags, $options->exposedTo, $options->mode, false, ToolDefinition::KIND_RESOURCE, $class, $resource->name(), $uri, $template, $variables, $options->variablePattern);
    }

    /** @param  list<Exclusion>  $exclusions */
    private function processPrompt(mixed $entry, ServerDefinition $definition, array &$exclusions): void
    {
        $class = $this->classOf($entry);
        if (AttributeResolver::webMcp($class) !== null) {
            throw InvalidWebMcpTargetException::prompt($class);
        }
        $exclusions[] = new Exclusion($definition->slug, $class, ExclusionReason::Prompt);
    }

    /**
     * @param list<ToolDefinition> $resourceTools
     */
    private function genericReader(ServerDefinition $definition, ?WebMcp $serverAttribute, NameRegistry $names, array $resourceTools): ToolDefinition
    {
        $name = $this->finalName('read-resource', $definition, $serverAttribute, $definition->class);
        $names->claim($name, ToolDefinition::claimLabel($definition->slug, $definition->class, ToolDefinition::KIND_GENERIC_RESOURCE));
        $allowed = array_map(static fn (ToolDefinition $tool): string => $tool->uriTemplate ?? (string) $tool->uri, $resourceTools);

        return new ToolDefinition($definition->slug, $name, $this->localizer->message('generic_reader_title'), $this->localizer->message('generic_reader_description', ['uris' => implode(', ', $allowed)]), ['type' => 'object', 'properties' => ['uri' => ['type' => 'string', 'description' => $this->localizer->message('generic_reader_uri')]], 'required' => ['uri']], ['readOnlyHint' => true, 'untrustedContentHint' => true], [], $this->resolveOptions(null, $serverAttribute, $definition, $definition->class)->mode, false, ToolDefinition::KIND_GENERIC_RESOURCE, $definition->class, 'read-resource', allowedUris: $allowed);
    }

    /**
     * @param list<ToolDefinition> $tools
     * @param list<Exclusion> $exclusions
     *
     * @return array{0: list<ToolDefinition>, 1: list<Exclusion>}
     */
    private function applyLimit(ServerDefinition $definition, array $tools, array $exclusions): array
    {
        $max = $this->settings->int('limits.max_tools', 64);

        if ($max < 1 || count($tools) <= $max) {
            return [$tools, $exclusions];
        }

        $dropped = array_slice($tools, $max);

        Log::warning('WebMCP: tool limit exceeded, dropping tools.', ['server' => $definition->slug, 'limit' => $max, 'dropped' => array_map(static fn (ToolDefinition $tool): string => $tool->name, $dropped)]);
        foreach ($dropped as $tool) {
            $exclusions[] = new Exclusion($definition->slug, $tool->source, ExclusionReason::Limit, $tool->name);
        }

        return [array_slice($tools, 0, $max), $exclusions];
    }

    private function configuredConfirm(?WebMcp $own, ?WebMcp $server): ?bool
    {
        return $own->confirm ?? $server->confirm ?? $this->settings->nullableBool('defaults.confirm');
    }

    /**
     * Effective settings. Priority: class attribute > server attribute > server registration > config.
     *
     * @param class-string $source
     */
    private function resolveOptions(?WebMcp $own, ?WebMcp $server, ServerDefinition $definition, string $source): ResolvedOptions
    {
        $mode = match (true) {
            $own !== null && $own->mode !== WebMcpMode::Inherit => $own->mode,
            $server !== null && $server->mode !== WebMcpMode::Inherit => $server->mode,
            $definition->mode !== null && $definition->mode !== WebMcpMode::Inherit => $definition->mode,
            default => $this->settings->defaultMode(),
        };
        $requested = match (true) {
            $own !== null && $own->exposedTo !== [] => $own->exposedTo,
            $server !== null => $server->exposedTo,
            default => [],
        };

        return new ResolvedOptions($mode, $own?->name, $requested === [] ? [] : OriginAllowlist::assertAllowed($requested, $this->settings->strings('allowed_origins'), $source), $this->configuredConfirm($own, $server), $own !== null && $own->untrusted, $own !== null && $own->debugging, $own?->variablePattern);
    }

    private function exposesAll(?WebMcp $serverAttribute): bool
    {
        return $serverAttribute !== null && $serverAttribute->exposeAll;
    }

    /**
     * @param class-string $class
     */
    private function assertNotServerOnly(?WebMcp $own, string $class): void
    {
        if ($own !== null && ($own->exposeAll || $own->prefix !== null)) {
            throw new InvalidWebMcpConfigurationException("#[WebMcp(exposeAll/prefix)] on [{$class}] is only valid on a Server class.");
        }
    }

    /**
     * @param class-string $source
     */
    private function finalName(string $base, ServerDefinition $definition, ?WebMcp $serverAttribute, string $source): string
    {
        $name = ($serverAttribute !== null && $serverAttribute->prefix !== null ? $serverAttribute->prefix : ($definition->prefix ?? '')).$base;
        if (!ToolNameValidator::isValid($name)) {
            throw new InvalidWebMcpConfigurationException("WebMCP tool name [{$name}] of [{$source}] is invalid: use 1-128 characters of A-Z a-z 0-9 _ - . ".'Set #[WebMcp(name: ...)] to override.');
        }

        return $name;
    }

    /**
     * Guarantees a complete {type: object, properties, required} schema.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        $required = array_values((array) ($schema['required'] ?? []));
        unset($schema['type'], $schema['properties'], $schema['required']);

        return ['type' => 'object', 'properties' => $properties === [] ? new stdClass() : $properties, 'required' => $required, ...$schema];
    }

    /**
     * @param array<string, mixed> $annotations
     */
    private function annotationSentence(array $annotations): string
    {
        $parts = [];
        if (isset($annotations['audience']) && is_array($annotations['audience'])) {
            $roles = array_filter($annotations['audience'], is_string(...));
            $parts[] = $this->localizer->message('audience', ['value' => implode(', ', $roles)]);
        }
        if (isset($annotations['priority']) && is_scalar($annotations['priority'])) {
            $parts[] = $this->localizer->message('priority', ['value' => (string) $annotations['priority']]);
        }
        if (isset($annotations['lastModified']) && is_scalar($annotations['lastModified'])) {
            $parts[] = $this->localizer->message('last_modified', ['value' => (string) $annotations['lastModified']]);
        }

        return $parts === [] ? '' : ' '.implode(' ', $parts);
    }

    private function description(string $description, string $title): string
    {
        $description = trim($description);

        return $this->limit($description === '' ? $title : $description, 'limits.max_description');
    }

    private function limit(string $value, string $key): string
    {
        $max = $this->settings->int($key, 0);
        if ($max < 2 || mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }
}
