<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;
use SytxLabs\LaravelWebMcp\Support\Settings;
use SytxLabs\LaravelWebMcp\View\NonceResolver;

/**
 * Behind `@webmcpActions`: renders the actions of the current Livewire component as an inert JSON element inside the component.
 * The Livewire adapter (webmcp-livewire.js) reads it when the component initializes and again after every update.
 */
final class LivewireActions
{
    public const string VIEW = 'webmcp::livewire';

    private const int JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(private readonly ActionManifest $actions, private readonly NonceResolver $nonce, private readonly Settings $settings, private readonly ViewFactory $views)
    {
    }

    public function render(?object $component): HtmlString
    {
        if (!$component instanceof Component) {
            throw new InvalidWebMcpConfigurationException('@webmcpActions can only be used inside a Livewire component view.');
        }
        if (!$this->settings->bool('livewire.enabled', true)) {
            return new HtmlString('');
        }
        $payload = $this->actions->for($component);

        return $payload['tools'] === [] ? new HtmlString('') : new HtmlString($this->views->make(self::VIEW, ['id' => $component->getId(), 'json' => json_encode($payload, self::JSON_FLAGS), 'nonce' => $this->nonce->resolve()])->render());
    }
}
