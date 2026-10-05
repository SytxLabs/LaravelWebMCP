<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Execution;

use Illuminate\Contracts\Container\Container;
use Laravel\Mcp\Request as McpRequest;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Contracts\AuthorizesWebMcpRead;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpArgumentsException;
use SytxLabs\LaravelWebMcp\Exceptions\WebMcpForbiddenException;
use SytxLabs\LaravelWebMcp\Manifest\Manifest;
use SytxLabs\LaravelWebMcp\Manifest\ToolDefinition;
use SytxLabs\LaravelWebMcp\Support\ResourceVariables;

/**
 * Everything that must hold before a resource is read on behalf of an agent, shared by the Session executor and the Bridge guard:
 * variable validation (path traversal), unambiguous URI composition, allowlist resolution for URIs, and the per-read authorization contract (IDOR).
 */
final readonly class ResourceAccess
{
    public function __construct(private Container $container, private ResourceVariables $variables)
    {
    }

    /**
     * Dedicated resource tool: validate the variables, compose the URI, authorize.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws InvalidWebMcpArgumentsException
     * @throws WebMcpForbiddenException
     *
     * @return array{0: string, 1: array<string, string>} URI and validated variables
     */
    public function forTool(ToolDefinition $tool, array $arguments): array
    {
        $variables = $this->variables->validate($tool, $arguments);
        $uri = $this->variables->compose($tool, $variables);
        $this->authorize($tool, $variables, $uri);

        return [$uri, $variables];
    }

    /**
     * Generic reader / Bridge: resolve a full URI against the exposed resource tools only (allowlist).
     *
     * @throws InvalidWebMcpArgumentsException
     * @throws WebMcpForbiddenException
     *
     * @return array{0: string, 1: array<string, string>, 2: ToolDefinition} URI, validated variables and the matched resource tool
     */
    public function forUri(Manifest $manifest, string $uri, WebMcpMode $mode): array
    {
        if ($uri === '' || strlen($uri) > 2048) {
            throw new InvalidWebMcpArgumentsException('Argument [uri] must be a non-empty string.');
        }

        foreach ($manifest->tools as $candidate) {
            if ($candidate->kind !== ToolDefinition::KIND_RESOURCE || $candidate->mode !== $mode) {
                continue;
            }
            $variables = $this->variables->match($candidate, $uri);
            if ($variables === null) {
                continue;
            }
            $validated = $this->variables->validate($candidate, $this->variables->decodeMatched($variables));
            if ($this->variables->compose($candidate, $validated) !== $uri) {
                throw new InvalidWebMcpArgumentsException('Invalid resource URI.');
            }
            $this->authorize($candidate, $validated, $uri);

            return [$uri, $validated, $candidate];
        }
        throw new InvalidWebMcpArgumentsException('This URI is not available.');
    }

    /** @param  array<string, string>  $variables */
    private function authorize(ToolDefinition $tool, array $variables, string $uri): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $resource = $this->container->make($tool->source);

        if ($resource instanceof AuthorizesWebMcpRead && !$resource->authorizeWebMcpRead(new McpRequest($variables, null, $uri), $variables)) {
            throw new WebMcpForbiddenException('You are not allowed to read this resource.');
        }
    }
}
