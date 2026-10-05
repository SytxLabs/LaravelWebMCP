<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Observability;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use SytxLabs\LaravelWebMcp\Events\WebMcpToolFailed;
use SytxLabs\LaravelWebMcp\Events\WebMcpToolInvoked;
use SytxLabs\LaravelWebMcp\Events\WebMcpToolSucceeded;
use SytxLabs\LaravelWebMcp\Manifest\ToolDefinition;
use SytxLabs\LaravelWebMcp\Support\Settings;

/**
 * Turns a tool call into Laravel events (WebMcpToolInvoked, Succeeded, Failed) and, when enabled, an audit log entry. Arguments are only attached when configured, and always masked.
 */
final readonly class Recorder
{
    public function __construct(private Container $container, private AuthFactory $auth, private Settings $settings, private ArgumentMasker $masker, private AuditLogger $audit)
    {
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function start(ToolDefinition $tool, array $arguments): Invocation
    {
        $user = $this->user();
        $consequential = ($tool->annotations['consequentialHint'] ?? false) === true;
        $eventArguments = $this->settings->bool('events.include_arguments', false) ? $this->masker->mask($arguments) : null;
        $this->events()->dispatch(new WebMcpToolInvoked($user, $tool->server, $tool->name, $tool->kind, $tool->mode->value, $consequential, $eventArguments));

        return new Invocation($this, $tool, $user, $consequential, hrtime(true), $eventArguments, $this->audit->wantsArguments() ? $this->masker->mask($arguments) : null);
    }

    public function finish(Invocation $invocation, ?string $failureReason): void
    {
        $duration = round((hrtime(true) - $invocation->startedAt) / 1_000_000, 3);
        $tool = $invocation->tool;

        $this->events()->dispatch($failureReason === null
            ? new WebMcpToolSucceeded($invocation->user, $tool->server, $tool->name, $tool->kind, $tool->mode->value, $invocation->consequential, $duration, $invocation->eventArguments)
            : new WebMcpToolFailed($invocation->user, $tool->server, $tool->name, $tool->kind, $tool->mode->value, $invocation->consequential, $duration, $failureReason, $invocation->eventArguments));

        $this->audit->log($invocation, $duration, $failureReason);
    }

    /**
     * Resolved on use: route controllers and the facade root outlive a request (and Event::fake()).
     */
    private function events(): Dispatcher
    {
        return $this->container->make(Dispatcher::class);
    }

    public function user(): ?Authenticatable
    {
        return $this->auth->guard()->user();
    }
}
