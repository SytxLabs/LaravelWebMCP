<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Execution;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use JsonException;
use SytxLabs\LaravelWebMcp\Support\Settings;

/**
 * Optional server-enforced confirmation for consequential tools (config confirmation.server_enforced).
 *
 * Two-step: the first call gets a single-use token bound to user/session, tool and arguments; the call is only executed when repeated with that token.
 * This is a speed bump that stops blind one-shot calls, NOT proof of a user gesture: a script on the page can still repeat the call. Real protection stays authorization.
 */
final readonly class ConfirmationGate
{
    public function __construct(private Cache $cache, private AuthFactory $auth, private Request $request, private Settings $settings)
    {
    }

    public function ttl(): int
    {
        return max(10, $this->settings->int('confirmation.ttl', 120));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return string|null a fresh token when confirmation is still required, null when the call may proceed
     */
    public function challenge(string $server, string $tool, array $arguments, ?string $token): ?string
    {
        $binding = $this->fingerprint($server, $tool, $arguments);
        if ($token !== null && $token !== '') {
            $stored = $this->cache->pull($this->key($token));
            if (is_string($stored) && hash_equals($stored, $binding)) {
                return null;
            }
        }
        $fresh = Str::random(40);
        $this->cache->put($this->key($fresh), $binding, $this->ttl());

        return $fresh;
    }

    /** @param  array<string, mixed>  $arguments */
    private function fingerprint(string $server, string $tool, array $arguments): string
    {
        try {
            $args = json_encode($this->canonical($arguments), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $args = serialize($this->canonical($arguments));
        }

        return hash('sha256', implode('|', [$server, $tool, $args, $this->identity()]));
    }

    private function identity(): string
    {
        $id = $this->auth->guard()->id();
        if (is_string($id) || is_int($id)) {
            return 'user:'.$id;
        }

        return $this->request->hasSession() ? 'session:'.$this->request->session()->getId() : 'ip:'.$this->request->ip();
    }

    private function key(string $token): string
    {
        return 'webmcp:confirm:'.hash('sha256', $token);
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map($this->canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
