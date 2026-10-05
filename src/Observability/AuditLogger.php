<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Observability;

use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use SytxLabs\LaravelWebMcp\Support\Settings;

/**
 * Optional audit log of WebMCP calls (config webmcp.audit). By default only consequential tools
 * (#[IsDestructive] / confirm) are logged, with user, IP, outcome, duration and masked arguments.
 */
final readonly class AuditLogger
{
    public function __construct(private LogManager $logs, private Request $request, private Settings $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('audit.enabled', false);
    }

    public function wantsArguments(): bool
    {
        return $this->enabled() && $this->settings->bool('audit.include_arguments', true);
    }

    public function log(Invocation $invocation, float $durationMs, ?string $failureReason): void
    {
        if (!$this->enabled()) {
            return;
        }
        if (!$invocation->consequential && $this->settings->bool('audit.only_consequential', true)) {
            return;
        }
        $channel = $this->settings->string('audit.channel', '');
        $tool = $invocation->tool;
        $context = [
            'user_id' => $invocation->user?->getAuthIdentifier(),
            'ip' => $this->request->ip(),
            'server' => $tool->server,
            'tool' => $tool->name,
            'kind' => $tool->kind,
            'mode' => $tool->mode->value,
            'consequential' => $invocation->consequential,
            'status' => $failureReason === null ? 'success' : 'failed',
            'reason' => $failureReason,
            'duration_ms' => $durationMs,
        ];
        if ($invocation->auditArguments !== null) {
            $context['arguments'] = $invocation->auditArguments;
        }
        $this->logs->channel($channel === '' ? null : $channel)->info('webmcp.tool', $context);
    }
}
