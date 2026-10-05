<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Livewire;

/**
 * Optional helpers for Livewire components with #[WebMcpAction] methods. The attribute works without this trait; override these hooks when you need them.
 *
 * Place `@webmcpActions` once inside the component's Blade view to publish the actions to the browser.
 */
trait ExposesWebMcpActions
{
    /** Return a key when the component can appear several times on one page ("product-{$this->product->id}"), so every instance registers uniquely named tools. null = no suffix. */
    public function webMcpInstanceKey(): ?string
    {
        return null;
    }

    /** Hide an action for the current state or user. Not an authorization check: the method itself must still authorize (Livewire runs it for every caller). */
    public function webMcpAvailable(string $method): bool
    {
        return true;
    }
}
