<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use SytxLabs\LaravelWebMcp\View\Renderer;

/**
 * <x-webmcp::tools :server="WeatherServer::class" />.
 *
 * Returns a View, never a raw string: a string would be compiled as Blade and the manifest content
 * (tool descriptions!) could then run `{{ }}` expressions.
 */
class Tools extends Component
{
    /** @param  class-string|list<string>|string|null  $server */
    public function __construct(public array|string|null $server = null, public bool $script = true)
    {
    }

    public function render(): View
    {
        return view(Renderer::VIEW, app(Renderer::class)->data($this->server, $this->script));
    }
}
