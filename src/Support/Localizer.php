<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Support;

use Illuminate\Contracts\Translation\Translator;

/**
 * Descriptions can be plain text or translation keys. A string that exists as a key (`group.key` or a
 * JSON translation) is translated into the current app locale, anything else is used as written.
 * Tool names are never translated: they are identifiers.
 *
 * The sentences the package generates itself ("Reads the resource ...") come from the `webmcp::messages`
 * translation group (English and German included; publish and override them like any package translation).
 */
final readonly class Localizer
{
    public function __construct(private Translator $translator)
    {
    }

    public function text(string $value): string
    {
        if ($value === '' || !method_exists($this->translator, 'has') || !$this->translator->has($value)) {
            return $value;
        }
        $translated = $this->translator->get($value);

        return is_string($translated) ? $translated : $value;
    }

    /**
     * A package message, e.g. message('reads_resource', ['uri' => $uri]).
     *
     * @param array<string, float|int|string> $replace
     */
    public function message(string $key, array $replace = []): string
    {
        $translated = $this->translator->get('webmcp::messages.'.$key, $replace);

        return is_string($translated) ? $translated : $key;
    }

    /**
     * Localizes every `title` and `description` string of a JSON Schema (properties, items, nested schemas).
     * Keys named "description" that hold a schema (a property called "description") are left alone.
     *
     * @param array<array-key, mixed> $schema
     *
     * @return array<array-key, mixed>
     */
    public function schema(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (in_array($key, ['default', 'enum', 'const', 'examples'], true)) {
                continue; // data, not documentation
            }
            if (is_array($value)) {
                $schema[$key] = $this->schema($value);
            } elseif (($key === 'description' || $key === 'title') && is_string($value)) {
                $schema[$key] = $this->text($value);
            }
        }

        return $schema;
    }
}
