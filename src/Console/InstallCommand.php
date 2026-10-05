<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

/** Publishes the config and the browser assets and prints the remaining manual steps. */
class InstallCommand extends Command
{
    protected $name = 'webmcp:install';

    protected $description = 'Publish the WebMCP config and browser assets and show the next steps';

    /** @return array<int, array<int, mixed>> */
    protected function getOptions(): array
    {
        return [
            ['force', null, InputOption::VALUE_NONE, 'Overwrite published files'],
        ];
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->callSilent('vendor:publish', ['--tag' => 'webmcp-config', '--force' => $force]);
        $this->callSilent('vendor:publish', ['--tag' => 'webmcp-assets', '--force' => $force]);

        $this->components->info('Published config/webmcp.php and public/vendor/webmcp/*.');

        $this->newLine();
        $this->line('Next steps:');
        $this->components->bulletList([
            'Register your laravel/mcp servers in config/webmcp.php (or WebMcp::server(...) in a service provider).',
            'Add #[WebMcp] to every tool and resource that should be available to the browser. Nothing is exposed without it.',
            'Put <meta name="csrf-token" content="{{ csrf_token() }}"> and @webmcp(YourServer::class) in your layout.',
            'Check what is exposed: php artisan webmcp:list --user=1   |   in CI: php artisan webmcp:check',
            'CSP: Vite::useCspNonce() or WebMcp::nonceUsing(fn () => ...) adds the nonce to the manifest and runtime tags.',
            'Iframes: WebMCP is off by default for cross-origin frames; allow it with <iframe allow="tools">. Disable it per page with the header Permissions-Policy: tools=().',
            'Bridge mode: add ->middleware(SytxLabs\LaravelWebMcp\Http\Middleware\EnforceWebMcpExposure::class.\':your-slug\') to the Mcp::web() route.',
        ]);

        return self::SUCCESS;
    }
}
