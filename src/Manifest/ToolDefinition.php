<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Manifest;

use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;

/** One exposed WebMCP tool. The `source`, `mcpName`, `uri` and `allowedUris` fields are internal and never serialized. */
final readonly class ToolDefinition
{
    public const string KIND_TOOL = 'tool';

    public const string KIND_RESOURCE = 'resource';

    public const string KIND_GENERIC_RESOURCE = 'generic-resource';

    /**
     * @param array<string, mixed> $inputSchema
     * @param array<string, bool> $annotations Only flags that are true.
     * @param list<string> $exposedTo
     * @param class-string $source
     * @param list<string> $variableNames Resource templates: URI template variables.
     * @param list<string> $allowedUris Generic reader: URIs / templates it may resolve.
     */
    public function __construct(
        public string $server,
        public string $name,
        public string $title,
        public string $description,
        public array $inputSchema,
        public array $annotations,
        public array $exposedTo,
        public WebMcpMode $mode,
        public bool $confirm,
        public string $kind,
        public string $source,
        public string $mcpName,
        public ?string $uri = null,
        public ?string $uriTemplate = null,
        public array $variableNames = [],
        public ?string $variablePattern = null,
        public array $allowedUris = [],
    ) {
    }

    /** Identifies who owns a tool name for the page-wide NameRegistry. Same owner claiming again is fine. */
    public static function claimLabel(string $server, string $source, string $kind): string
    {
        return $server.'/'.$source.match ($kind) {
            self::KIND_RESOURCE => ' (resource tool)', self::KIND_GENERIC_RESOURCE => ' (generic resource reader)', default => ''
        };
    }

    /**
     * Browser-facing shape. No class names, paths or secrets.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'mode' => $this->mode->value,
            'kind' => $this->kind,
            'confirm' => $this->confirm,
        ];
        if ($this->annotations !== []) {
            $data['annotations'] = $this->annotations;
        }
        if ($this->exposedTo !== []) {
            $data['exposedTo'] = $this->exposedTo;
        }
        if ($this->kind === self::KIND_RESOURCE && $this->uriTemplate !== null) {
            $data['uriTemplate'] = $this->uriTemplate;
        }

        return $data;
    }
}
