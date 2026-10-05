<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Attributes;

use Attribute;
use InvalidArgumentException;
use SytxLabs\LaravelWebMcp\Support\ToolNameValidator;

/**
 * Exposes a public Livewire component method as a WebMCP tool (opt-in per method).
 * The browser runs it with `$wire.$call(...)`, so Livewire's own pipeline applies: update checksum, method authorization attributes, validation, rate limits and your policies.
 * The attribute adds theagent as a caller; it does not add any server-side capability a user could not already trigger.
 *
 *     #[WebMcpAction(description: 'Adds a product to the cart', parameters: ['qty' => 'How many'])]
 *     public function addToCart(int $productId, int $qty = 1) { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class WebMcpAction
{
    /**
     * @param string $description Plain text or a translation key.
     * @param string|null $name Tool name; default "<component>.<method>" in kebab case.
     * @param bool|null $confirm null = true when `consequential`.
     * @param bool $consequential Sets consequentialHint (significant, real-world, hard to undo).
     * @param array<string, string> $parameters Parameter descriptions by parameter name (text or translation keys).
     * @param array{properties?: array<string, mixed>, required?: list<string>}|null $schema Replaces the schema derived from the method signature.
     */
    public function __construct(public string $description, public ?string $name = null, public ?string $title = null, public ?bool $confirm = null, public bool $readOnly = false, public bool $consequential = false, public bool $untrusted = false, public array $parameters = [], public ?array $schema = null)
    {
        if ($description === '') {
            throw new InvalidArgumentException('#[WebMcpAction] needs a description.');
        }
        if ($name !== null && !ToolNameValidator::isValid($name)) {
            throw new InvalidArgumentException("Invalid WebMCP tool name [{$name}]: use 1-128 characters of A-Z a-z 0-9 _ - .");
        }
        if ($readOnly && $consequential) {
            throw new InvalidArgumentException('An action cannot be both readOnly and consequential.');
        }
    }
}
