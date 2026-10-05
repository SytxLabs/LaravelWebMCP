<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use SytxLabs\LaravelWebMcp\Console\Concerns\ImpersonatesUser;
use SytxLabs\LaravelWebMcp\Exceptions\UnknownWebMcpServerException;
use SytxLabs\LaravelWebMcp\Manifest\Manifest;
use SytxLabs\LaravelWebMcp\Manifest\NameRegistry;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;
use SytxLabs\LaravelWebMcp\WebMcpManager;
use Throwable;

/**
 * Shows what WebMCP exposes, and why something is not exposed (missing attribute, shouldRegister, app-only, prompt, template without authorization, limit ...). shouldRegister() runs as the given --user.
 */
class ListCommand extends Command
{
    use ImpersonatesUser;

    protected $name = 'webmcp:list';

    protected $description = 'List the tools and resource tools WebMCP exposes, and the reason for every exclusion';

    /** @return array<int, array<int, mixed>> */
    protected function getArguments(): array
    {
        return [['server', InputArgument::OPTIONAL, 'Server slug or server class (default: all registered servers)']];
    }

    /** @return array<int, array<int, mixed>> */
    protected function getOptions(): array
    {
        return [
            ['user', null, InputOption::VALUE_REQUIRED, 'Evaluate shouldRegister() as the user with this id'],
            ['guard', null, InputOption::VALUE_REQUIRED, 'Guard used to find the user'],
            ['exposed', null, InputOption::VALUE_NONE, 'Only list exposed tools'],
            ['json', null, InputOption::VALUE_NONE, 'Output JSON'],
        ];
    }

    public function handle(WebMcpManager $manager, ServerRegistry $servers, AuthFactory $auth): int
    {
        if (!$this->actAsRequestedUser($auth)) {
            return self::FAILURE;
        }

        try {
            $server = $this->argument('server');
            $slugs = (is_string($server) && $server !== '') ? [$servers->resolve($server)->slug] : array_keys($servers->all());
        } catch (UnknownWebMcpServerException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($slugs === []) {
            $this->components->warn('No WebMCP servers are registered. Add them to config/webmcp.php or call WebMcp::server(...).');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = false;

        foreach ($slugs as $slug) {
            try {
                $rows = [...$rows, ...$this->rows($manager->manifest($slug, new NameRegistry()), (bool) $this->option('exposed'))];
            } catch (Throwable $e) {
                $failed = true;
                $rows[] = ['server' => $slug, 'tool' => '-', 'kind' => '-', 'mode' => '-', 'source' => '-', 'status' => 'ERROR: '.$e->getMessage()];
            }
        }

        if ($this->option('json')) {
            /** @noinspection JsonEncodingApiUsageInspection */
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }
        $this->table(['Server', 'Tool', 'Kind', 'Mode', 'Source', 'Status'], array_map(array_values(...), $rows));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<array{server: string, tool: string, kind: string, mode: string, source: string, status: string}> */
    private function rows(Manifest $manifest, bool $onlyExposed): array
    {
        $rows = [];
        foreach ($manifest->tools as $tool) {
            $rows[] = ['server' => $manifest->server, 'tool' => $tool->name, 'kind' => $tool->kind, 'mode' => $tool->mode->value, 'source' => $tool->source, 'status' => 'exposed'.($tool->confirm ? ' (confirm)' : '')];
        }
        if (!$onlyExposed) {
            foreach ($manifest->exclusions as $exclusion) {
                $rows[] = ['server' => $manifest->server, 'tool' => '-', 'kind' => '-', 'mode' => '-', 'source' => $exclusion->source, 'status' => 'excluded: '.$exclusion->reason->value.($exclusion->detail !== null ? ' ('.$exclusion->detail.')' : '')];
            }
        }

        return $rows;
    }
}
