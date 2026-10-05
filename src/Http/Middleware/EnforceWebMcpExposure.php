<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpArgumentsException;
use SytxLabs\LaravelWebMcp\Exceptions\WebMcpForbiddenException;
use SytxLabs\LaravelWebMcp\Execution\ConfirmationGate;
use SytxLabs\LaravelWebMcp\Execution\ResourceAccess;
use SytxLabs\LaravelWebMcp\Manifest\ManifestBuilder;
use SytxLabs\LaravelWebMcp\Manifest\ToolDefinition;
use SytxLabs\LaravelWebMcp\Observability\Invocation;
use SytxLabs\LaravelWebMcp\Observability\Recorder;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;
use SytxLabs\LaravelWebMcp\Support\Json;
use SytxLabs\LaravelWebMcp\Support\Settings;

/**
 * Optional guard for Mcp::web() routes used by Bridge mode:
 *
 *     Mcp::web('/mcp/weather', WeatherServer::class)->middleware(EnforceWebMcpExposure::class.':weather');   // WebMCP server slug
 *
 * Mcp::web() exposes EVERY tool of the server to any caller that may reach the endpoint, independent of #[WebMcp]. A cookie-authenticated browser script (XSS!) could therefore call tools you never opted in.
 * This guard enforces the opt-in for browser-style requests: only tools/call and resources/read, only for tools and resources the current user sees in the Bridge manifest, with the same resource checks as Session mode.
 *
 * "Browser-style" (config bridge.enforce = browser): the WebMCP marker header is present or the session cookie is sent.
 * The marker is client-controlled and no boundary on its own; the cookie is what matters. Bearer-token MCP clients without either are untouched. Use bridge.enforce = always to guard every caller.
 */
final readonly class EnforceWebMcpExposure
{
    public function __construct(private ServerRegistry $servers, private ManifestBuilder $builder, private ResourceAccess $resources, private ConfirmationGate $confirmation, private Recorder $recorder, private Settings $settings)
    {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, string $server): Response
    {
        if (!match ($this->settings->string('bridge.enforce', 'browser')) {
            'never' => false,
            'always' => true,
            default => $request->headers->has($this->settings->string('header', 'X-WebMCP')) || $request->cookies->has($this->sessionCookie()),
        }) {
            return $next($request);
        }

        $definition = $this->servers->get($server);
        /** @var array<mixed> $rpc a JSON list (batch) decodes to a list, which is rejected below */
        $rpc = $request->json()->all();

        if ($rpc !== [] && array_is_list($rpc)) {
            return $this->reject(null, 400, -32600, 'Invalid request.');
        }

        $id = is_int($rpc['id'] ?? null) || is_string($rpc['id'] ?? null) ? $rpc['id'] : null;
        $method = is_string($rpc['method'] ?? null) ? $rpc['method'] : '';
        $params = is_array($rpc['params'] ?? null) ? Json::assoc($rpc['params']) : [];

        if (in_array($method, ['ping', 'initialize'], true)) {
            return $next($request);
        }

        if (!in_array($method, ['tools/call', 'resources/read'], true)) {
            return $this->reject($id, 403, -32003, 'This method is not available to WebMCP clients.');
        }
        $manifest = $this->builder->build($definition);

        if ($method === 'tools/call') {
            $name = $params['name'] ?? null;
            $tool = null;
            foreach ($manifest->tools as $candidate) {
                if ($candidate->kind === ToolDefinition::KIND_TOOL && $candidate->mode === WebMcpMode::Bridge && $candidate->mcpName === $name) {
                    $tool = $candidate;
                }
            }
            if ($tool === null) {
                return $this->reject($id, 403, -32003, 'This tool is not available to WebMCP clients.');
            }

            $arguments = is_array($params['arguments'] ?? null) ? Json::assoc($params['arguments']) : [];
            $invocation = $this->recorder->start($tool, $arguments);

            if ($tool->confirm && $this->settings->bool('confirmation.server_enforced', false)) {
                $header = $request->header($this->settings->string('confirmation.header', 'X-WebMCP-Confirmation'));
                $token = $this->confirmation->challenge($definition->slug, $tool->name, $arguments, is_string($header) ? $header : null);
                if ($token !== null) {
                    $invocation->fail('confirmation-required');

                    return $this->reject($id, 428, -32004, 'Confirmation required.', ['confirmation' => ['required' => true, 'token' => $token, 'expiresIn' => $this->confirmation->ttl()]]);
                }
            }

            return $this->observe($invocation, $next($request));
        }

        $uri = $params['uri'] ?? null;
        if (!is_string($uri)) {
            return $this->reject($id, 400, -32602, 'Missing [uri] parameter.');
        }
        try {
            [, , $resource] = $this->resources->forUri($manifest, $uri, WebMcpMode::Bridge);
        } catch (WebMcpForbiddenException $e) {
            return $this->reject($id, 403, -32003, $e->getMessage());
        } catch (InvalidWebMcpArgumentsException $e) {
            return $this->reject($id, 400, -32602, $e->getMessage());
        }

        return $this->observe($this->recorder->start($resource, ['uri' => $uri]), $next($request));
    }

    /** Records the outcome of a call the MCP endpoint answered. Streamed (SSE) answers cannot be inspected without consuming them, so a 2xx stream counts as success. */
    private function observe(Invocation $invocation, Response $response): Response
    {
        if ($response->getStatusCode() >= 400) {
            $invocation->fail('http-'.$response->getStatusCode());

            return $response;
        }

        /** @noinspection JsonEncodingApiUsageInspection */
        $decoded = $response instanceof JsonResponse || str_contains((string) $response->headers->get('Content-Type'), 'json') ? json_decode((string) $response->getContent(), true) : null;
        (is_array($decoded) && (isset($decoded['error']) || ((is_array($decoded['result'] ?? null) ? $decoded['result'] : [])['isError'] ?? false) === true)) ? $invocation->fail('tool-error') : $invocation->succeed();

        return $response;
    }

    private function sessionCookie(): string
    {
        $name = config('session.cookie');

        return is_string($name) ? $name : 'laravel_session';
    }

    /** @param  array<string, mixed>|null  $data */
    private function reject(int|string|null $id, int $status, int $code, string $message, ?array $data = null): JsonResponse
    {
        return new JsonResponse(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message, ...($data === null ? [] : ['data' => $data])]], $status, ['Cache-Control' => 'no-store, private']);
    }
}
