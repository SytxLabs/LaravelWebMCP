<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Exceptions\WebMcpForbiddenException;
use SytxLabs\LaravelWebMcp\Exceptions\WebMcpToolNotFoundException;
use SytxLabs\LaravelWebMcp\Execution\ToolExecutor;
use SytxLabs\LaravelWebMcp\Manifest\ToolDefinition;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;

/**
 * Session mode: POST {prefix}/{server}/tools/{tool} and POST {prefix}/{server}/resources/{resource}.
 * Always JSON, never cached. Agent mistakes (bad arguments, validation) are 200 results with isError; structural problems (unknown tool, forbidden) are HTTP errors the client maps and refreshes on.
 */
final class InvokeController
{
    private const array HEADERS = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly ServerRegistry $servers, private readonly ToolExecutor $executor)
    {
    }

    public function tool(Request $request, string $server, string $tool): JsonResponse
    {
        return $this->invoke($request, $server, $tool, [ToolDefinition::KIND_TOOL]);
    }

    public function resource(Request $request, string $server, string $resource): JsonResponse
    {
        return $this->invoke($request, $server, $resource, [ToolDefinition::KIND_RESOURCE, ToolDefinition::KIND_GENERIC_RESOURCE]);
    }

    /**
     * @param list<string> $kinds
     */
    private function invoke(Request $request, string $server, string $name, array $kinds): JsonResponse
    {
        if (!$this->servers->has($server)) {
            return $this->error(404, 'tool_not_found', 'Unknown WebMCP tool.');
        }

        $payload = $request->json()->all();
        $arguments = $payload['arguments'] ?? [];
        $confirmation = $payload['confirmation'] ?? null;
        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return $this->error(400, 'invalid_arguments', 'The "arguments" member must be a JSON object.');
        }
        if ($confirmation !== null && !is_string($confirmation)) {
            return $this->error(400, 'invalid_arguments', 'The "confirmation" member must be a string.');
        }
        try {
            /** @var array<string, mixed> $arguments */
            $result = $this->executor->execute($this->servers->get($server), $name, $arguments, WebMcpMode::Session, $kinds, $confirmation);
        } catch (WebMcpToolNotFoundException) {
            return $this->error(404, 'tool_not_found', 'Unknown WebMCP tool.');
        } catch (WebMcpForbiddenException $e) {
            return $this->error(403, 'forbidden', $e->getMessage());
        }

        return new JsonResponse($result, 200, self::HEADERS);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status, self::HEADERS);
    }
}
