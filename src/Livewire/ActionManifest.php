<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Livewire;

use Illuminate\Support\Str;
use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use stdClass;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpAction;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;
use SytxLabs\LaravelWebMcp\Manifest\ManifestPayload;
use SytxLabs\LaravelWebMcp\Manifest\NameRegistry;
use SytxLabs\LaravelWebMcp\Support\Localizer;
use SytxLabs\LaravelWebMcp\Support\ToolNameValidator;

/**
 * Builds the tool definitions of one Livewire component instance from its #[WebMcpAction] methods.
 *
 * Optional hooks on the component (see ExposesWebMcpActions):
 *   webMcpInstanceKey(): ?string       makes names unique when the component appears several times
 *   webMcpAvailable(string $method)    hides an action for the current state/user
 */
final class ActionManifest
{
    /** Livewire lifecycle and framework methods that must never become agent tools. */
    private const array BLOCKED = ['mount', 'boot', 'booted', 'render', 'rendering', 'rendered', 'placeholder', 'exception', 'dehydrate', 'hydrate', 'updating', 'updated', 'paginationView', 'paginators'];

    /** @var array<class-string, bool> */
    private static array $hasActions = [];

    public function __construct(private readonly ActionSchema $schemas, private readonly Localizer $localizer, private readonly NameRegistry $names)
    {
    }

    /**
     * Whether the component class has any #[WebMcpAction] method. Cached per class (no request state).
     */
    public static function hasActions(object $component): bool
    {
        return self::$hasActions[$component::class] ??= (static function () use ($component): bool {
            foreach ((new ReflectionClass($component))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getAttributes(WebMcpAction::class) !== []) {
                    return true;
                }
            }

            return false;
        })();
    }

    /**
     * @return array<string, mixed>
     */
    public function for(Component $component): array
    {
        $tools = [];
        $class = new ReflectionClass($component);
        $key = $this->instanceKey($component);

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(WebMcpAction::class);
            if ($attributes === []) {
                continue;
            }
            $name = $method->getName();
            if ($method->isStatic() || str_starts_with($name, '__') || in_array($name, self::BLOCKED, true) || preg_match('/^(updat(ed|ing)|hydrate|dehydrate)[A-Z]/', $name) === 1) {
                throw new InvalidWebMcpConfigurationException(sprintf('#[WebMcpAction] on %s::%s() is not allowed: lifecycle, static and magic methods cannot be agent tools.', $method->getDeclaringClass()->getName(), $name));
            }
            if (method_exists($component, 'webMcpAvailable') && $component->webMcpAvailable($method->getName()) === false) {
                continue;
            }
            $tools[] = $this->tool($component, $method, $attributes[0]->newInstance(), $key);
        }

        return ['version' => ManifestPayload::VERSION, 'component' => $component->getId(), 'tools' => $tools];
    }

    /**
     * @return array<string, mixed>
     */
    private function tool(Component $component, ReflectionMethod $method, WebMcpAction $action, ?string $key): array
    {
        $base = $action->name ?? Str::kebab(class_basename($component)).'.'.Str::kebab($method->getName());
        $name = $key === null ? $base : $base.'.'.$key;
        if (!ToolNameValidator::isValid($name)) {
            throw new InvalidWebMcpConfigurationException(sprintf('WebMCP tool name [%s] of %s::%s() is invalid: use 1-128 characters of A-Z a-z 0-9 _ - . Set #[WebMcpAction(name: ...)].', $name, $method->getDeclaringClass()->getName(), $method->getName()));
        }
        $id = $component->getId();
        $this->names->claim($name, 'livewire/'.$component::class.'::'.$method->getName().'@'.(is_string($id) ? $id : ''));

        $derived = $this->schemas->for($method, $action);

        $annotations = [];
        if ($action->readOnly) {
            $annotations['readOnlyHint'] = true;
        }
        if ($action->consequential) {
            $annotations['consequentialHint'] = true;
        }
        if ($action->untrusted) {
            $annotations['untrustedContentHint'] = true;
        }
        $tool = [
            'name' => $name,
            'title' => $action->title !== null ? $this->localizer->text($action->title) : Str::headline($method->getName()),
            'description' => $this->localizer->text($action->description),
            'inputSchema' => $derived['schema'],
            'kind' => 'livewire',
            'confirm' => $action->confirm ?? $action->consequential,
            'livewire' => [
                'method' => $method->getName(),
                'params' => $derived['params'],
                'defaults' => $derived['defaults'] === [] ? new stdClass() : $derived['defaults'],
                'variadic' => $derived['variadic'],
            ],
        ];
        if ($annotations !== []) {
            $tool['annotations'] = $annotations;
        }

        return $tool;
    }

    private function instanceKey(Component $component): ?string
    {
        if (!method_exists($component, 'webMcpInstanceKey')) {
            return null;
        }
        $key = $component->webMcpInstanceKey();
        if (!is_string($key) || $key === '') {
            return null;
        }

        return Str::slug($key, '-');
    }
}
