<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Livewire;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use stdClass;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpAction;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;
use SytxLabs\LaravelWebMcp\Support\Localizer;

/**
 * Derives the WebMCP input schema of a Livewire action from the method signature.
 * Livewire calls actions with positional arguments, so next to the schema it returns the parameter order and the defaults the browser needs to fill gaps.
 *
 *   int -> integer, float -> number, string -> string, bool -> boolean, array -> array,
 *   BackedEnum -> enum of its values, Eloquent model -> its key (integer|string, Livewire binds it),
 *   ?T / T|null -> nullable and optional, default value -> optional, variadic -> array.
 * Anything else (objects, closures, pure enums, intersections) needs an explicit `schema:`.
 */
final readonly class ActionSchema
{
    public function __construct(private Localizer $localizer)
    {
    }

    /** @return array{schema: array<string, mixed>, params: list<string>, defaults: array<string, mixed>, variadic: string|null} */
    public function for(ReflectionMethod $method, WebMcpAction $action): array
    {
        $params = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters());
        $defaults = [];

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $default = $parameter->getDefaultValue();

                if ($this->jsonSafe($default)) {
                    $defaults[$parameter->getName()] = $default;
                }
            }
        }

        if ($action->schema !== null) {
            $properties = $action->schema['properties'] ?? [];

            return [
                'schema' => ['type' => 'object', 'properties' => $properties === [] ? new stdClass() : $properties, 'required' => $action->schema['required'] ?? []],
                'params' => $params,
                'defaults' => $defaults,
                'variadic' => $this->variadic($method),
            ];
        }

        $properties = [];
        $required = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $property = $this->property($method, $parameter);
            if (isset($action->parameters[$name])) {
                $property['description'] = $this->localizer->text($action->parameters[$name]);
            }
            if (array_key_exists($name, $defaults) && $parameter->isDefaultValueAvailable()) {
                $property['default'] = $defaults[$name];
            }
            $properties[$name] = $property;
            if (!$parameter->isOptional() && !($parameter->getType()?->allowsNull() ?? false)) {
                $required[] = $name;
            }
        }

        return [
            'schema' => ['type' => 'object', 'properties' => $properties === [] ? new stdClass() : $properties, 'required' => $required],
            'params' => $params,
            'defaults' => $defaults,
            'variadic' => $this->variadic($method),
        ];
    }

    private function variadic(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                return $parameter->getName();
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function property(ReflectionMethod $method, ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        if ($type === null) {
            return [];
        }
        $schema = $this->typeSchema($method, $parameter, $type);
        if ($parameter->isVariadic()) {
            return ['type' => 'array', 'items' => $schema];
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function typeSchema(ReflectionMethod $method, ReflectionParameter $parameter, ReflectionType $type): array
    {
        if ($type instanceof ReflectionIntersectionType) {
            throw $this->unmappable($method, $parameter, 'intersection types');
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = [];
            $nullable = false;
            foreach ($type->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType && $member->getName() === 'null') {
                    $nullable = true;

                    continue;
                }
                $parts[] = $this->typeSchema($method, $parameter, $member);
            }

            return $this->combine($parts, $nullable);
        }
        /** @var ReflectionNamedType $type */
        $name = $type->getName();
        $schema = match ($name) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'string' => ['type' => 'string'],
            'bool', 'false', 'true' => ['type' => 'boolean'],
            'array', 'iterable' => ['type' => 'array'],
            'mixed' => [],
            'null' => ['type' => 'null'],
            default => $this->classSchema($method, $parameter, $name),
        };

        return $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? $this->combine([$schema], true) : $schema;
    }

    /** @return array<string, mixed> */
    private function classSchema(ReflectionMethod $method, ReflectionParameter $parameter, string $class): array
    {
        if (is_subclass_of($class, BackedEnum::class)) {
            $backing = (new ReflectionEnum($class))->getBackingType();

            return [
                'type' => $backing instanceof ReflectionNamedType && $backing->getName() === 'int' ? 'integer' : 'string',
                'enum' => array_map(static fn (BackedEnum $case): int|string => $case->value, $class::cases()),
            ];
        }
        if (is_subclass_of($class, Model::class)) {
            return [
                'type' => ['integer', 'string'],
                'description' => 'ID of the '.class_basename($class).' (Livewire resolves the model).',
            ];
        }
        throw $this->unmappable($method, $parameter, "type [{$class}]");
    }

    /**
     * @param list<array<string, mixed>> $parts
     *
     * @return array<string, mixed>
     */
    private function combine(array $parts, bool $nullable): array
    {
        $simple = true;
        $types = [];

        foreach ($parts as $part) {
            if (count(array_diff(array_keys($part), ['type'])) > 0 || !isset($part['type'])) {
                $simple = false;
                break;
            }

            foreach ((array) $part['type'] as $type) {
                if (is_string($type)) {
                    $types[] = $type;
                }
            }
        }

        if ($simple && $parts !== []) {
            $types = array_values(array_unique($nullable ? [...$types, 'null'] : $types));

            return ['type' => count($types) === 1 ? $types[0] : $types];
        }
        if ($nullable) {
            $parts[] = ['type' => 'null'];
        }

        return count($parts) === 1 ? $parts[0] : ['anyOf' => $parts];
    }

    private function unmappable(ReflectionMethod $method, ReflectionParameter $parameter, string $what): InvalidWebMcpConfigurationException
    {
        return new InvalidWebMcpConfigurationException(sprintf('#[WebMcpAction] on %s::%s(): parameter $%s uses %s, which has no JSON Schema mapping. Provide an explicit schema: [...].', $method->getDeclaringClass()->getName(), $method->getName(), $parameter->getName(), $what));
    }

    private function jsonSafe(mixed $value): bool
    {
        return is_scalar($value) || $value === null || (is_array($value) && json_encode($value) !== false);
    }
}
