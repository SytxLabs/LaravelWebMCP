<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Execution;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Support\ValidationMessages;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpArgumentsException;
use SytxLabs\LaravelWebMcp\Exceptions\WebMcpForbiddenException;
use SytxLabs\LaravelWebMcp\Exceptions\WebMcpToolNotFoundException;
use SytxLabs\LaravelWebMcp\Manifest\ManifestBuilder;
use SytxLabs\LaravelWebMcp\Manifest\ToolDefinition;
use SytxLabs\LaravelWebMcp\Mapping\ResultMapper;
use SytxLabs\LaravelWebMcp\Observability\Recorder;
use SytxLabs\LaravelWebMcp\Servers\ServerDefinition;
use SytxLabs\LaravelWebMcp\Support\Settings;
use SytxLabs\LaravelWebMcp\Testing\WebMcpFake;
use Throwable;

/**
 * Runs one WebMCP tool call in-process. Authorization is decided here on EVERY call:
 * the tool must be in the manifest built for the current request/user (opt-in attribute, shouldRegister, mode), never merely "was once shown in the page".
 */
final readonly class ToolExecutor
{
    public function __construct(private ManifestBuilder $builder, private McpDispatcher $dispatcher, private ResultMapper $mapper, private ResourceAccess $resources, private ConfirmationGate $confirmation, private Recorder $recorder, private Settings $settings)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $kinds allowed ToolDefinition kinds for this entry point
     *
     * @throws WebMcpForbiddenException
     * @throws WebMcpToolNotFoundException
     *
     * @return array<string, mixed> result envelope in the browser's execute() format
     */
    public function execute(ServerDefinition $server, string $toolName, array $arguments, WebMcpMode $via, array $kinds, ?string $confirmation = null): array
    {
        $manifest = $this->builder->build($server);
        $tool = $manifest->tool($toolName);
        if ($tool === null || $tool->mode !== $via || !in_array($tool->kind, $kinds, true)) {
            throw WebMcpToolNotFoundException::named($toolName);
        }
        $invocation = $this->recorder->start($tool, $arguments);
        if ($tool->confirm && $this->settings->bool('confirmation.server_enforced', false)) {
            $token = $this->confirmation->challenge($server->slug, $tool->name, $arguments, $confirmation);
            if ($token !== null) {
                $invocation->fail('confirmation-required');

                return $this->mapper->confirmationRequired($token, $this->confirmation->ttl());
            }
        }
        if (app()->bound(WebMcpFake::class) && ($stub = app(WebMcpFake::class)->stubFor($tool->name)) !== null) {
            ($stub['isError'] ?? false) === true ? $invocation->fail('tool-error') : $invocation->succeed();

            return $stub;
        }
        try {
            $result = match ($tool->kind) {
                ToolDefinition::KIND_TOOL => $this->mapper->tool($this->dispatcher->callTool($server, $tool->mcpName, $arguments)),
                ToolDefinition::KIND_GENERIC_RESOURCE => $this->mapper->resource($this->dispatcher->readResource($server, $this->resources->forUri($manifest, $this->uriArgument($arguments), $tool->mode)[0])),
                default => $this->mapper->resource($this->dispatcher->readResource($server, $this->resources->forTool($tool, $arguments)[0])),
            };
        } catch (WebMcpForbiddenException|WebMcpToolNotFoundException $e) {
            $invocation->fail('forbidden');
            throw $e;
        } catch (InvalidWebMcpArgumentsException $e) {
            $invocation->fail('invalid-arguments');

            return $this->mapper->error($e->getMessage());
        } catch (ValidationException $e) {
            $invocation->fail('invalid-arguments');

            return $this->mapper->error(ValidationMessages::from($e));
        } catch (Throwable $e) {
            report($e);
            $invocation->fail('exception');

            return $this->mapper->error('An internal server error occurred.');
        }
        ($result['isError'] ?? false) === true ? $invocation->fail('tool-error') : $invocation->succeed();

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function uriArgument(array $arguments): string
    {
        foreach (array_keys($arguments) as $key) {
            if ($key !== 'uri') {
                throw new InvalidWebMcpArgumentsException('Unknown argument.');
            }
        }
        $uri = $arguments['uri'] ?? null;
        if (!is_string($uri)) {
            throw new InvalidWebMcpArgumentsException('Argument [uri] must be a non-empty string.');
        }

        return $uri;
    }
}
