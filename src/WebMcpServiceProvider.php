<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp;

use Composer\InstalledVersions;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\ComponentHookRegistry;
use SytxLabs\LaravelWebMcp\Cache\FlushManifestCache;
use SytxLabs\LaravelWebMcp\Console\CheckCommand;
use SytxLabs\LaravelWebMcp\Console\InstallCommand;
use SytxLabs\LaravelWebMcp\Console\ListCommand;
use SytxLabs\LaravelWebMcp\Forms\DeclarativeForms;
use SytxLabs\LaravelWebMcp\Http\Middleware\EnsureWebMcpRequest;
use SytxLabs\LaravelWebMcp\Livewire\LivewireActions;
use SytxLabs\LaravelWebMcp\Livewire\ReportsValidationErrors;
use SytxLabs\LaravelWebMcp\Manifest\ManifestBuilder;
use SytxLabs\LaravelWebMcp\Manifest\ManifestCompiler;
use SytxLabs\LaravelWebMcp\Manifest\NameRegistry;
use SytxLabs\LaravelWebMcp\Servers\ServerRegistry;
use SytxLabs\LaravelWebMcp\Support\Localizer;
use SytxLabs\LaravelWebMcp\Support\Settings;
use SytxLabs\LaravelWebMcp\View\Renderer;

class WebMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/webmcp.php', 'webmcp');

        if (class_exists(ComponentHookRegistry::class) && config('webmcp.livewire.enabled', true)) {
            ComponentHookRegistry::register(ReportsValidationErrors::class);
        }

        $this->app->bind(Settings::class, fn (Application $app): Settings => new Settings($app->make(Repository::class)));
        $this->app->singleton(ServerRegistry::class, function (Application $app): ServerRegistry {
            $registry = new ServerRegistry();
            $registry->registerMany($app->make(Settings::class)->array('servers'));

            return $registry;
        });
        $this->app->scoped(NameRegistry::class);
        $this->app->bind(ManifestCompiler::class, fn (Application $app): ManifestCompiler => new ManifestCompiler($app, $app->make(Settings::class), $app->make(Localizer::class)));
        $this->app->bind(WebMcpManager::class, fn (Application $app): WebMcpManager => new WebMcpManager($app->make(ServerRegistry::class), $app->make(ManifestBuilder::class)));
    }

    public function boot(): void
    {
        $settings = $this->app->make(Settings::class);

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'webmcp');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'webmcp');
        Event::listen([Login::class, Logout::class, PasswordReset::class, CurrentDeviceLogout::class, OtherDeviceLogout::class], FlushManifestCache::class);
        Blade::componentNamespace('SytxLabs\\LaravelWebMcp\\View\\Components', 'webmcp');
        // @webmcp(WeatherServer::class)  |  @webmcp([A::class, B::class])  |  @webmcp  |  @webmcp(Server::class, false)
        Blade::directive('webmcp', static fn (string $expression): string => '<?php echo app(\\'.Renderer::class.'::class)->render('.($expression === '' ? 'null' : $expression).'); ?>');

        // @webmcpActions inside a Livewire component view publishes its #[WebMcpAction] methods.
        Blade::directive('webmcpActions', fn (): string => '<?php echo app(\\'.LivewireActions::class.'::class)->render(isset($this) ? $this : null); ?>');

        // Declarative forms: annotate an existing <form> / control from an exposed tool.
        //   <form method="get" action="/search" @webmcpForm(SearchProductsTool::class)>
        //   <input name="query" @webmcpParam(SearchProductsTool::class, 'query')>
        Blade::directive('webmcpForm', static fn (string $expression): string => '<?php echo app(\\'.DeclarativeForms::class.'::class)->attributes('.$expression.'); ?>');
        Blade::directive('webmcpParam', static fn (string $expression): string => '<?php echo app(\\'.DeclarativeForms::class.'::class)->param('.$expression.'); ?>');

        RateLimiter::for('webmcp', static function (Request $request) use ($settings): Limit {
            $id = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(max(1, $settings->int('rate_limit.per_minute', 60)))->by(is_int($id) || is_string($id) ? 'user:'.$id : 'ip:'.$request->ip());
        });

        if ($settings->bool('routes.enabled', true)) {
            Route::middleware([...$settings->strings('routes.middleware'), EnsureWebMcpRequest::class])->prefix(trim($settings->string('routes.prefix', 'webmcp'), '/'))->group(fn () => $this->loadRoutesFrom(__DIR__.'/../routes/webmcp.php'));
        }

        if (class_exists(AboutCommand::class)) {
            AboutCommand::add('SytxLabs WebMCP', fn (): array => $this->aboutInformation());
        }

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, ListCommand::class, CheckCommand::class]);

            $this->publishes([__DIR__.'/../config/webmcp.php' => config_path('webmcp.php')], 'webmcp-config');
            $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/webmcp')], 'webmcp-lang');
            $this->publishes([
                __DIR__.'/../resources/js/webmcp.js' => public_path('vendor/webmcp/webmcp.js'),
                __DIR__.'/../resources/js/webmcp-core.js' => public_path('vendor/webmcp/webmcp-core.js'),
                __DIR__.'/../resources/js/webmcp.d.ts' => public_path('vendor/webmcp/webmcp.d.ts'),
                __DIR__.'/../resources/js/webmcp-livewire.js' => public_path('vendor/webmcp/webmcp-livewire.js'),
                __DIR__.'/../resources/js/webmcp-alpine.js' => public_path('vendor/webmcp/webmcp-alpine.js'),
                __DIR__.'/../resources/js/webmcp-forms.js' => public_path('vendor/webmcp/webmcp-forms.js'),
                __DIR__.'/../resources/css/webmcp-forms.css' => public_path('vendor/webmcp/webmcp-forms.css'),
            ], 'webmcp-assets');
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/webmcp'),
            ], 'webmcp-views');
        }
    }

    /** @return array<string, string> */
    private function aboutInformation(): array
    {
        $settings = $this->app->make(Settings::class);
        $servers = array_keys($this->app->make(ServerRegistry::class)->all());
        $pageLimit = $settings->int('limits.max_tools_per_page', 128);

        return [
            'Version' => $this->installedVersion('sytxlabs/laravel-webmcp'),
            'Author' => 'SytxLabs',
            'Enabled' => $settings->bool('enabled', true) ? 'yes' : 'no',
            'Servers' => $servers === [] ? 'none' : implode(', ', $servers),
            'Routes' => $settings->bool('routes.enabled', true) ? '/'.trim($settings->string('routes.prefix', 'webmcp'), '/') : 'disabled',
            'Bridge guard' => $settings->string('bridge.enforce', 'browser'),
            'Server-enforced confirmation' => $settings->bool('confirmation.server_enforced', false) ? 'yes' : 'no',
            'Tools per page' => $pageLimit > 0 ? (string) $pageLimit : 'unlimited',
            'Manifest cache' => $settings->bool('cache.enabled', false) ? 'on' : 'off',
            'Audit log' => $settings->bool('audit.enabled', false) ? 'on' : 'off',
            'Livewire' => class_exists(ComponentHookRegistry::class) ? $this->installedVersion('livewire/livewire') : 'not installed',
        ];
    }

    private function installedVersion(string $package): string
    {
        return InstalledVersions::isInstalled($package) ? (InstalledVersions::getPrettyVersion($package) ?? 'unknown') : 'unknown';
    }
}
