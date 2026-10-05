<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Forms;

/**
 * One generated form control.
 *
 * @phpstan-type Option array{value: string, label: string}
 */
final readonly class FormField
{
    public const string KIND_INPUT = 'input';

    public const string KIND_SELECT = 'select';

    public const string KIND_CHECKBOX = 'checkbox';

    /**
     * @param string $type JSON Schema type: string | integer | number | boolean
     * @param string $inputType HTML input type (input kind only)
     * @param array<string, bool|float|int|string> $attributes extra HTML attributes (min, max, step, minlength, maxlength, pattern)
     * @param list<array{value: string, label: string}> $options select options
     */
    public function __construct(public string $name, public string $kind, public string $type, public string $label, public ?string $description, public bool $required, public string $inputType = 'text', public array $attributes = [], public array $options = [], public bool|float|int|string|null $default = null)
    {
    }

    public function id(string $prefix): string
    {
        return $prefix.'-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $this->name);
    }
}
