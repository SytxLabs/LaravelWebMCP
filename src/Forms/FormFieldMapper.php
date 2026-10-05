<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Forms;

use Illuminate\Support\Str;
use SytxLabs\LaravelWebMcp\Exceptions\UnmappableSchemaException;
use SytxLabs\LaravelWebMcp\Support\Json;

/**
 * Maps a tool's JSON Schema to form controls (declarative WebMCP forms).
 *
 *   string                      text; format email|uri|date|date-time|time -> email|url|date|datetime-local|time
 *                               minLength/maxLength/pattern -> minlength/maxlength/pattern
 *   integer / number            number; minimum/maximum -> min/max; step 1 / multipleOf / any
 *   boolean                     checkbox (unchecked sends nothing, the browser/our script reads it as false)
 *   enum (string|integer|number) select
 *   nullable ("type": [T, "null"]) the field for T, optional
 *
 * Not mappable: object, array (even of enums), oneOf/anyOf/allOf/not/$ref/if-then-else, patternProperties, prefixItems, binary/file strings, multi-type unions, exclusive bounds are ignored (HTML cannot express them), properties without a type or enum.
 * The WebMCP declarative spec does not define the schema synthesis yet (index.bs: TODO); this follows the conservative reading of the explainer: `name` is the property, `toolparamdescription` its description.
 */
final class FormFieldMapper
{
    private const array UNSUPPORTED_KEYWORDS = ['oneOf', 'anyOf', 'allOf', 'not', '$ref', 'if', 'then', 'else', 'patternProperties', 'prefixItems', 'contentMediaType', 'contentEncoding'];

    private const array FORMATS = ['email' => 'email', 'uri' => 'url', 'url' => 'url', 'date' => 'date', 'date-time' => 'datetime-local', 'time' => 'time'];

    /**
     * @param array<string, mixed> $schema complete {type: object, properties, required}
     * @param list<string> $except properties the caller renders itself
     *
     * @return list<FormField>
     */
    public function map(string $tool, array $schema, array $except = []): array
    {
        $properties = $schema['properties'] ?? [];
        if (is_object($properties)) {
            $properties = (array) $properties;
        }
        if (!is_array($properties)) {
            return [];
        }

        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $fields = [];
        foreach ($properties as $name => $property) {
            $name = (string) $name;
            if (in_array($name, $except, true)) {
                continue;
            }
            $fields[] = $this->field($tool, $name, is_array($property) ? Json::assoc($property) : [], in_array($name, $required, true));
        }

        return $fields;
    }

    /** @param  array<string, mixed>  $property */
    private function field(string $tool, string $name, array $property, bool $required): FormField
    {
        foreach (self::UNSUPPORTED_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $property)) {
                throw UnmappableSchemaException::property($tool, $name, "it uses [{$keyword}]");
            }
        }
        if (($property['format'] ?? null) === 'binary') {
            throw UnmappableSchemaException::property($tool, $name, 'file uploads (format binary) are not supported');
        }
        $type = $this->type($tool, $name, $property);
        $label = is_string($property['title'] ?? null) ? $property['title'] : Str::headline($name);
        $description = is_string($property['description'] ?? null) && $property['description'] !== '' ? $property['description'] : null;
        $default = is_scalar($property['default'] ?? null) ? $property['default'] : null;

        if (is_array($property['enum'] ?? null)) {
            return new FormField($name, FormField::KIND_SELECT, $type, $label, $description, $required, options: $this->options($tool, $name, $property['enum']), default: $default);
        }

        return match ($type) {
            'boolean' => new FormField($name, FormField::KIND_CHECKBOX, 'boolean', $label, $description, $required, default: $default),
            'integer', 'number' => new FormField($name, FormField::KIND_INPUT, $type, $label, $description, $required, 'number', $this->numberAttributes($type, $property), default: $default),
            default => $this->stringField($name, $label, $description, $required, $property, $default),
        };
    }

    /** @param  array<string, mixed>  $property */
    private function stringField(string $name, string $label, ?string $description, bool $required, array $property, bool|float|int|string|null $default): FormField
    {
        $attributes = [];
        foreach (['minLength' => 'minlength', 'maxLength' => 'maxlength'] as $keyword => $attribute) {
            if (is_int($property[$keyword] ?? null)) {
                $attributes[$attribute] = $property[$keyword];
            }
        }
        if (is_string($property['pattern'] ?? null)) {
            $attributes['pattern'] = $property['pattern'];
        }

        return new FormField($name, FormField::KIND_INPUT, 'string', $label, $description, $required, self::FORMATS[(is_string($property['format'] ?? null) ? $property['format'] : null) ?? ''] ?? 'text', $attributes, default: $default);
    }

    /**
     * @param array<string, mixed> $property
     */
    private function type(string $tool, string $name, array $property): string
    {
        $type = $property['type'] ?? null;
        if (is_array($type)) {
            $type = array_values(array_filter($type, static fn (mixed $t): bool => $t !== 'null'));
            if (count($type) !== 1) {
                throw UnmappableSchemaException::property($tool, $name, 'it is a union of several types');
            }
            $type = $type[0];
        }
        if ($type === null && is_array($property['enum'] ?? null) && $property['enum'] !== []) {
            $type = match (true) {
                is_int($property['enum'][0]) => 'integer', is_float($property['enum'][0]) => 'number', default => 'string'
            };
        }

        return match ($type) {
            'string', 'integer', 'number', 'boolean' => $type,
            'array' => throw UnmappableSchemaException::property($tool, $name, 'arrays have no form control (a repeated field name is lost by classic PHP form posts)'),
            'object' => throw UnmappableSchemaException::property($tool, $name, 'nested objects have no form control'),
            default => throw UnmappableSchemaException::property($tool, $name, 'it has no usable "type"'),
        };
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<array{value: string, label: string}>
     */
    private function options(string $tool, string $name, array $values): array
    {
        $options = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                throw UnmappableSchemaException::property($tool, $name, 'its enum contains non-scalar values');
            }
            $options[] = ['value' => (string) $value, 'label' => is_string($value) ? Str::headline($value) : (string) $value];
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $property
     *
     * @return array<string, float|int|string>
     */
    private function numberAttributes(string $type, array $property): array
    {
        $attributes = [];
        if (is_int($property['minimum'] ?? null) || is_float($property['minimum'] ?? null)) {
            $attributes['min'] = $property['minimum'];
        }
        if (is_int($property['maximum'] ?? null) || is_float($property['maximum'] ?? null)) {
            $attributes['max'] = $property['maximum'];
        }
        $multipleOf = $property['multipleOf'] ?? null;
        $attributes['step'] = is_int($multipleOf) || is_float($multipleOf) ? $multipleOf : ($type === 'integer' ? 1 : 'any');

        return $attributes;
    }
}
