<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Mapping;

use SytxLabs\LaravelWebMcp\Execution\McpOutcome;
use SytxLabs\LaravelWebMcp\Support\Json;
use SytxLabs\LaravelWebMcp\Support\Settings;

/**
 * Maps laravel/mcp results to the shape the browser returns from execute():
 *
 *   { content: [{ type: "text", text }], isError?: true }
 *
 * The spec leaves the result format open (`Promise<any>`); this is the MCP-style shape its explainer uses. Errors are resolved results, not rejections: a rejected execute() reaches the agent as a bare UnknownError.
 */
final readonly class ResultMapper
{
    public function __construct(private Settings $settings)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function tool(McpOutcome $outcome): array
    {
        if ($outcome->result === null || $outcome->failed()) {
            return $this->error($this->rpcErrorMessage($outcome));
        }

        $result = $outcome->result;
        $content = [];
        $items = is_array($result['content'] ?? null) ? $result['content'] : [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $content[] = $this->toolContent(Json::assoc($item));
            }
        }
        $envelope = ['content' => $content === [] ? [['type' => 'text', 'text' => '']] : $content];
        if (($result['isError'] ?? false) === true) {
            $envelope['isError'] = true;
        }
        if (isset($result['structuredContent']) && $this->settings->bool('features.structured_content', false)) {
            $envelope['structuredContent'] = $result['structuredContent'];
        }

        return $envelope;
    }

    /**
     * @return array<string, mixed>
     */
    public function resource(McpOutcome $outcome): array
    {
        if ($outcome->result === null || $outcome->failed()) {
            return $this->error($this->rpcErrorMessage($outcome));
        }
        $content = collect(is_array($outcome->result['contents'] ?? null) ? $outcome->result['contents'] : [])
            ->filter(fn ($item) => is_array($item))->map(fn ($item) => $this->resourceContent(Json::assoc($item)))->values()->all();

        return ['content' => $content === [] ? [['type' => 'text', 'text' => '']] : $content];
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, isError: true}
     */
    public function error(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmationRequired(string $token, int $ttl): array
    {
        return [
            ...$this->error('This action needs the user\'s confirmation before it can run. Ask the user, then repeat the call with the confirmation token.'),
            'confirmation' => ['required' => true, 'token' => $token, 'expiresIn' => $ttl],
        ];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{type: string, text: string}
     */
    private function toolContent(array $item): array
    {
        $type = is_string($item['type'] ?? null) ? $item['type'] : 'text';

        if ($type === 'text') {
            return ['type' => 'text', 'text' => is_string($item['text'] ?? null) ? $item['text'] : ''];
        }
        $mime = is_string($item['mimeType'] ?? null) ? $item['mimeType'] : 'unknown type';
        $bytes = is_string($item['data'] ?? null) ? (int) (strlen($item['data']) * 0.75) : 0;

        return ['type' => 'text', 'text' => "[{$type} content omitted: {$mime}".($bytes > 0 ? ", ~{$bytes} bytes" : '').']'];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function resourceContent(array $item): array
    {
        if (is_string($item['text'] ?? null)) {
            return ['type' => 'text', 'text' => $item['text']];
        }

        $mime = is_string($item['mimeType'] ?? null) ? $item['mimeType'] : 'application/octet-stream';
        $blob = is_string($item['blob'] ?? null) ? $item['blob'] : '';
        $bytes = (int) (strlen($blob) * 0.75);
        $limit = $this->settings->int('resources.blob_max_bytes', 262144);

        return match ($this->settings->string('resources.blobs', 'fallback')) {
            'mcp-image' => str_starts_with($mime, 'image/') && $bytes <= $limit ? ['type' => 'image', 'data' => $blob, 'mimeType' => $mime] : $this->blobFallback($mime, $bytes),
            'base64-text' => $bytes <= $limit ? ['type' => 'text', 'text' => "data:{$mime};base64,{$blob}"] : $this->blobFallback($mime, $bytes),
            default => $this->blobFallback($mime, $bytes),
        };
    }

    /**
     * @return array{type: string, text: string}
     */
    private function blobFallback(string $mime, int $bytes): array
    {
        return ['type' => 'text', 'text' => "[binary content omitted: {$mime}, ~{$bytes} bytes]"];
    }

    private function rpcErrorMessage(McpOutcome $outcome): string
    {
        $message = $outcome->error['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : 'The request could not be completed.';
    }
}
