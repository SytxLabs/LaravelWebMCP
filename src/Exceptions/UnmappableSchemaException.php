<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Exceptions;

/** A JSON Schema property has no HTML form control (see the "Declarative forms" section of the README). */
class UnmappableSchemaException extends InvalidWebMcpConfigurationException
{
    public static function property(string $tool, string $property, string $reason): self
    {
        return new self("Tool [{$tool}] property [{$property}] cannot be rendered as a form field: {$reason}. ".'Exclude it with :omit="[\''.$property.'\']" and supply the field yourself, or use the imperative tool instead of a declarative form.');
    }
}
