<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Livewire\Livewire;
use SytxLabs\LaravelWebMcp\Facades\WebMcp;
use SytxLabs\LaravelWebMcp\Http\Middleware\EnforceWebMcpExposure;
use Workbench\App\Livewire\Cart;
use Workbench\App\Mcp\ShopServer;

/**
 * Wires the example shop: one laravel/mcp server exposed to the browser, a demo login, a Livewire component.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Demo auth: a session flag stands in for a real login.
        config([
            'auth.guards.web' => ['driver' => 'demo', 'provider' => 'users'],
            'webmcp.audit.enabled' => true,
            // No database needed for the example.
            'session.driver' => 'file',
            'cache.default' => 'file',
        ]);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'workbench');

        Auth::viaRequest('demo', fn (Request $request) => $request->hasSession() && $request->session()->get('demo_user')
            ? new GenericUser(['id' => 1, 'name' => 'Demo user'])
            : null);

        Livewire::component('cart', Cart::class);

        // One registration serves Session mode (package routes) and Bridge mode (the Mcp::web() endpoint).
        WebMcp::server('shop', ShopServer::class, endpoint: '/mcp/shop');

        // Bridge mode: the MCP endpoint plus the guard that enforces #[WebMcp] for browser callers.
        Mcp::web('/mcp/shop', ShopServer::class)
            ->middleware(['web', EnforceWebMcpExposure::class.':shop']);

        Route::middleware('web')->group(__DIR__.'/../../routes/web.php');
    }
}
