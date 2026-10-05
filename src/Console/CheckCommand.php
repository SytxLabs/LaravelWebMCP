<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\InputOption;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use SytxLabs\LaravelWebMcp\Console\Concerns\ImpersonatesUser;
use SytxLabs\LaravelWebMcp\Manifest\NameRegistry;
use SytxLabs\LaravelWebMcp\Manifest\ToolDefinition;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;
use SytxLabs\LaravelWebMcp\Support\AttributeResolver;
use SytxLabs\LaravelWebMcp\Support\Settings;
use SytxLabs\LaravelWebMcp\WebMcpManager;
use Throwable;

/**
 * For CI: builds every manifest and fails on configuration errors (invalid names, collisions, #[WebMcp] on a prompt, confirm on a resource, unlisted exposedTo origins, Bridge tools without an endpoint ...).
 * Warns about risky settings (exposeAll, the generic reader, a disabled Bridge guard).
 */
class CheckCommand extends Command
{
    use ImpersonatesUser;

    protected $name = 'webmcp:check';

    protected $description = 'Validate the WebMCP configuration of every registered server (for CI)';

    /** @return array<int, array<int, mixed>> */
    protected function getOptions(): array
    {
        return [
            ['user', null, InputOption::VALUE_REQUIRED, 'Build the manifests as the user with this id'],
            ['guard', null, InputOption::VALUE_REQUIRED, 'Guard used to find the user'],
        ];
    }

    public function handle(WebMcpManager $manager, ServerRegistry $servers, Settings $settings, AuthFactory $auth): int
    {
        if (!$this->actAsRequestedUser($auth)) {
            return self::FAILURE;
        }

        $errors = 0;
        $warnings = 0;

        if ($servers->all() === []) {
            $this->components->warn('No WebMCP servers are registered.');

            return self::SUCCESS;
        }

        foreach ($servers->all() as $slug => $definition) {
            try {
                $manifest = $manager->manifest($slug, new NameRegistry());
            } catch (Throwable $e) {
                $errors++;
                $this->components->error("[{$slug}] ".$e->getMessage());

                continue;
            }

            $bridgeTools = array_filter($manifest->tools, static fn (ToolDefinition $t): bool => $t->mode === WebMcpMode::Bridge);
            if (!Route::has('webmcp.tools') && array_filter($manifest->tools, static fn (ToolDefinition $t): bool => $t->mode === WebMcpMode::Session) !== []) {
                $errors++;
                $this->components->error("[{$slug}] has Session-mode tools but the WebMCP routes are disabled (webmcp.routes.enabled).");
            }
            if ($bridgeTools !== [] && $definition->endpoint === null) {
                $errors++;
                $this->components->error("[{$slug}] has Bridge-mode tools but no 'endpoint' (the Mcp::web() URI) is registered.");
            }
            if ($bridgeTools !== [] && $settings->string('bridge.enforce', 'browser') === 'never') {
                $warnings++;
                $this->components->warn("[{$slug}] Bridge guard is disabled (webmcp.bridge.enforce=never): the MCP endpoint serves every tool to browser callers.");
            }
            if (AttributeResolver::webMcp($definition->class)?->exposeAll === true) {
                $warnings++;
                $this->components->warn("[{$slug}] uses #[WebMcp(exposeAll: true)]: every tool and resource without its own attribute is exposed.");
            }
            $this->components->info(sprintf('[%s] %d tool(s) exposed, %d excluded.', $slug, count($manifest->tools), count($manifest->exclusions)));
        }

        if ($errors === 0 && count($servers->all()) > 1) {
            try {
                $manager->manifests(null, new NameRegistry());
            } catch (Throwable $e) {
                $warnings++;
                $this->components->warn('Rendering all servers on ONE page would fail: '.$e->getMessage());
            }
        }

        if ($settings->bool('resources.generic_reader', false)) {
            $warnings++;
            $this->components->warn('The generic read-resource tool is enabled (webmcp.resources.generic_reader).');
        }

        if (!$settings->bool('resources.require_authorization', true)) {
            $warnings++;
            $this->components->warn('Template resources are exposed without AuthorizesWebMcpRead (webmcp.resources.require_authorization=false).');
        }

        $this->newLine();
        $this->line(sprintf('%d error(s), %d warning(s).', $errors, $warnings));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
