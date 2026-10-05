<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Execution;

use Closure;
use Laravel\Mcp\Server\Contracts\Transport;
use Stringable;

/** Transport that keeps a laravel/mcp server in-process: one request message in, all response messages (including notifications and streamed parts) collected. No HTTP, no output buffering. */
final class InMemoryTransport implements Transport
{
    private ?Closure $handler = null;

    /** @var list<string> */
    private array $messages = [];

    public function onReceive(Closure $handler): void
    {
        $this->handler = $handler;
    }

    public function send(string $message): void
    {
        $this->messages[] = $message;
    }

    /** Servers call this for generator responses; run it immediately and collect everything. */
    public function stream(Closure $stream): void
    {
        $result = $stream();

        if (is_iterable($result)) {
            foreach ($result as $message) {
                if (is_string($message) || $message instanceof Stringable) {
                    $this->send((string) $message);
                }
            }
        }
    }

    /** @return list<string> every message the server produced for $rawMessage */
    public function dispatch(string $rawMessage): array
    {
        $this->messages = [];
        if ($this->handler instanceof Closure) {
            ($this->handler)($rawMessage);
        }

        return $this->messages;
    }

    /** @return list<string> */
    public function run(): array
    {
        return $this->messages;
    }
}
