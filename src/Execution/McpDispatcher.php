<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Execution;

use Illuminate\Contracts\Container\Container;
use Laravel\Mcp\Server;
use SytxLabs\LaravelWebMcp\Exceptions\WebMcpException;
use SytxLabs\LaravelWebMcp\Servers\ServerDefinition;
use SytxLabs\LaravelWebMcp\Support\Json;
use Throwable;

/** Calls a laravel/mcp server in-process through its public API: the same JSON-RPC code path as Mcp::web(), minus HTTP. Request state (user, session, locale) is the current request's. */
final readonly class McpDispatcher
{
    public function __construct(private Container $container)
    {
    }

    /** @param  array<string, mixed>  $arguments */
    public function callTool(ServerDefinition $server, string $toolName, array $arguments): McpOutcome
    {
        return $this->send($server, 'tools/call', [
            'name' => $toolName,
            'arguments' => (object) $arguments,
        ]);
    }

    public function readResource(ServerDefinition $server, string $uri): McpOutcome
    {
        return $this->send($server, 'resources/read', ['uri' => $uri]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function send(ServerDefinition $definition, string $method, array $params): McpOutcome
    {
        $transport = new InMemoryTransport();

        /** @noinspection PhpUnhandledExceptionInspection */
        $server = $this->container->make($definition->class, ['transport' => $transport]);

        if (!$server instanceof Server) {
            throw new WebMcpException("[{$definition->class}] did not resolve to a laravel/mcp server.");
        }

        $server->start();

        try {
            $raw = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $result = null;
            $error = null;
            $notifications = 0;

            foreach ($transport->dispatch($raw) as $message) {
                $decoded = json_decode($message, true, 512, JSON_THROW_ON_ERROR);

                if (!is_array($decoded)) {
                    continue;
                }
                if (isset($decoded['error']) && is_array($decoded['error'])) {
                    $error = Json::assoc($decoded['error']);
                } elseif (isset($decoded['result']) && is_array($decoded['result'])) {
                    $result = Json::assoc($decoded['result']);
                } elseif (isset($decoded['method'])) {
                    $notifications++;
                }
            }

            return new McpOutcome($result, $result === null ? ($error ?? ['message' => 'The MCP server returned no response.']) : null, $notifications);
        } catch (Throwable $e) {
            throw new WebMcpException("Error dispatching to [{$definition->class}]: ".$e->getMessage(), 0, $e);
        }
    }
}
