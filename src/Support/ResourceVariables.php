<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Support;

use Laravel\Mcp\Support\UriTemplate;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpArgumentsException;
use SytxLabs\LaravelWebMcp\Manifest\ToolDefinition;

/**
 * Validates resource URI template variables sent by an agent and composes the URI.
 * laravel/mcp's UriTemplate matches variables with `[^/]+` and never decodes them, so `..`, `%2e%2e`, `%2F`, NUL and backslashes would reach the resource handler. They are rejected here, before dispatch.
 */
final readonly class ResourceVariables
{
    public function __construct(private Settings $settings)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, string>
     */
    public function validate(ToolDefinition $tool, array $arguments): array
    {
        foreach (array_keys($arguments) as $key) {
            if (!in_array((string) $key, $tool->variableNames, true)) {
                throw new InvalidWebMcpArgumentsException('Unknown argument ['.mb_substr(preg_replace('/[^\w.\-]/u', '?', (string) $key) ?? '', 0, 64).'].');
            }
        }
        $variables = [];
        foreach ($tool->variableNames as $name) {
            if (!array_key_exists($name, $arguments)) {
                throw new InvalidWebMcpArgumentsException("Missing required argument [{$name}].");
            }
            $value = $arguments[$name];
            if (is_int($value)) {
                $value = (string) $value;
            }
            if ($value === null) {
                throw new InvalidWebMcpArgumentsException("Invalid value for [{$name}].");
            }
            if (!is_string($value)) {
                throw new InvalidWebMcpArgumentsException("Argument [{$name}] must be a string.");
            }
            if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $this->settings->int('resources.variable_max_length', 255)) {
                throw new InvalidWebMcpArgumentsException("Invalid value for [{$name}].");
            }
            $candidate = $value;
            for ($layer = 0; $layer < 4; $layer++) {
                if ($candidate === '.' || $candidate === '..' || str_contains($candidate, '/') || str_contains($candidate, '\\') || str_contains($candidate, '?') || str_contains($candidate, '#') || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1 || !mb_check_encoding($candidate, 'UTF-8')) {
                    throw new InvalidWebMcpArgumentsException("Invalid value for [{$name}].");
                }
                $decoded = rawurldecode($candidate);
                if ($decoded === $candidate) {
                    break;
                }
                $candidate = $decoded;
            }
            if ($tool->variablePattern !== null && preg_match($tool->variablePattern, $value) !== 1) {
                throw new InvalidWebMcpArgumentsException("Invalid value for [{$name}].");
            }
            $variables[$name] = $value;
        }

        return $variables;
    }

    /**
     * @param array<string, string> $variables
     */
    public function compose(ToolDefinition $tool, array $variables): string
    {
        if ($tool->uri !== null) {
            return $tool->uri;
        }

        $template = (string) $tool->uriTemplate;
        $encode = $this->settings->bool('resources.encode_variables', false);

        $replacements = [];
        $expected = [];
        foreach ($variables as $name => $value) {
            $inserted = $encode ? rawurlencode($value) : $value;
            $replacements['{'.$name.'}'] = $inserted;
            $expected[$name] = $inserted;
        }
        $uri = strtr($template, $replacements);
        $matched = (new UriTemplate($template))->match($uri);
        if ($matched === null) {
            throw new InvalidWebMcpArgumentsException('The given values do not form an unambiguous resource URI.');
        }
        ksort($matched);
        ksort($expected);
        if ($matched !== $expected) {
            throw new InvalidWebMcpArgumentsException('The given values do not form an unambiguous resource URI.');
        }

        return $uri;
    }

    /**
     * Resolves a full URI against an exposed template/static resource (generic reader).
     *
     * @return array<string, string>|null variables, or null when the URI does not belong to the tool
     */
    public function match(ToolDefinition $tool, string $uri): ?array
    {
        return $tool->uri !== null ? ($tool->uri === $uri ? [] : null) : (new UriTemplate((string) $tool->uriTemplate))->match($uri);
    }

    /**
     * Variables returned by match() are in the form they appear in the URI; undo the encoding the composer applied.
     *
     * @param array<string, string> $variables
     *
     * @return array<string, string>
     */
    public function decodeMatched(array $variables): array
    {
        return $this->settings->bool('resources.encode_variables', false) ? array_map(rawurldecode(...), $variables) : $variables;
    }
}
