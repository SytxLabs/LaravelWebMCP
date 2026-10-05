<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Testing;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Assert;
use SytxLabs\LaravelWebMcp\Events\WebMcpToolInvoked;
use SytxLabs\LaravelWebMcp\WebMcpManager;

final class WebMcpFake
{
    /** @var list<WebMcpToolInvoked> */
    private array $invoked = [];

    /** @var array<string, array<string, mixed>> */
    private array $stubs = [];

    public function __construct(private readonly WebMcpManager $manager, Dispatcher $events)
    {
        $events->listen(WebMcpToolInvoked::class, function (WebMcpToolInvoked $event): void {
            $this->invoked[] = $event;
        });
    }

    /**
     * Answer a tool with this result without running it, e.g. respondWith('send-invoice', 'Sent.').
     *
     * @param array<string, mixed>|string $result text, or a complete {content: [...], isError?: bool} result
     */
    public function respondWith(string $tool, array|string $result): self
    {
        $this->stubs[$tool] = is_string($result) ? ['content' => [['type' => 'text', 'text' => $result]]] : $result;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function stubFor(string $tool): ?array
    {
        return $this->stubs[$tool] ?? null;
    }

    /**
     * Names of the tools the current user sees, optionally for one server.
     *
     * @return list<string>
     */
    public function exposedTools(?string $server = null): array
    {
        $names = [];

        foreach ($server !== null ? [$server] : array_keys($this->manager->servers()) as $slug) {
            array_push($names, ...$this->manager->manifest($slug)->toolNames());
        }

        return $names;
    }

    public function assertToolExposed(string $tool, ?string $server = null): self
    {
        Assert::assertContains($tool, $this->exposedTools($server), "Failed asserting that WebMCP tool [{$tool}] is exposed to the current user.");

        return $this;
    }

    public function assertToolNotExposed(string $tool, ?string $server = null): self
    {
        Assert::assertNotContains($tool, $this->exposedTools($server), "Failed asserting that WebMCP tool [{$tool}] is not exposed to the current user.");

        return $this;
    }

    /**
     * @param (Closure(WebMcpToolInvoked): bool)|null $callback extra condition on the recorded call
     */
    public function assertToolInvoked(string $tool, ?Closure $callback = null): self
    {
        Assert::assertNotSame($this->invocations($tool, $callback), [], "Failed asserting that WebMCP tool [{$tool}] was invoked".($callback === null ? '.' : ' with the given condition.'));

        return $this;
    }

    public function assertToolNotInvoked(string $tool): self
    {
        Assert::assertSame([], $this->invocations($tool), "Failed asserting that WebMCP tool [{$tool}] was not invoked.");

        return $this;
    }

    public function assertToolInvokedTimes(string $tool, int $times): self
    {
        Assert::assertCount($times, $this->invocations($tool), "Failed asserting that WebMCP tool [{$tool}] was invoked {$times} time(s).");

        return $this;
    }

    public function assertNothingInvoked(): self
    {
        Assert::assertSame([], $this->invoked, 'Failed asserting that no WebMCP tool was invoked.');

        return $this;
    }

    /**
     * @param (Closure(WebMcpToolInvoked): bool)|null $callback
     *
     * @return list<WebMcpToolInvoked>
     */
    public function invocations(?string $tool = null, ?Closure $callback = null): array
    {
        return array_values(array_filter($this->invoked, static fn (WebMcpToolInvoked $event): bool => ($tool === null || $event->tool === $tool) && ($callback === null || $callback($event))));
    }
}
