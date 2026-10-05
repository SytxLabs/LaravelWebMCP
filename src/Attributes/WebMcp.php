<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Attributes;

use Attribute;
use InvalidArgumentException;
use SytxLabs\LaravelWebMcp\Support\ToolNameValidator;

/**
 * Opt-in marker that exposes a laravel/mcp Tool or Resource as a WebMCP tool.
 * On a Server class it only provides defaults (mode, confirm, exposedTo, prefix). Nothing is exposed without its own attribute, except when the server sets `exposeAll: true` (RISKY, see README).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class WebMcp
{
    /**
     * @param WebMcpMode $mode Bridge | Session | Inherit.
     * @param string|null $name Overrides the WebMCP tool name.
     * @param list<string> $exposedTo Secure origins (must also be in config `webmcp.allowed_origins`).
     * @param bool|null $confirm null = derived from #[IsDestructive]. Not allowed on resources.
     * @param bool $allowAppOnly Allow tools with #[RendersApp(visibility: [Visibility::App])].
     * @param bool $untrusted Sets untrustedContentHint (tool output contains untrusted data).
     * @param bool $debugging Sets the spec's `debugging` annotation.
     * @param string|null $variablePattern Resources: regex every URI template variable must match.
     * @param bool $exposeAll SERVER ONLY. Exposes every tool/resource without its own attribute. RISKY.
     * @param string|null $prefix SERVER ONLY. Prefix for every WebMCP tool name of the server.
     * @param bool|null $cache Allow manifest caching (null = config default).
     * @param bool $sharedCache Allow a manifest cache entry shared between users (only with public Cacheable).
     */
    public function __construct(public WebMcpMode $mode = WebMcpMode::Inherit, public ?string $name = null, public array $exposedTo = [], public ?bool $confirm = null, public bool $allowAppOnly = false, public bool $untrusted = false, public bool $debugging = false, public ?string $variablePattern = null, public bool $exposeAll = false, public ?string $prefix = null, public ?bool $cache = null, public bool $sharedCache = false)
    {
        if ($name !== null && !ToolNameValidator::isValid($name)) {
            throw new InvalidArgumentException("Invalid WebMCP tool name [{$name}]: use 1-128 characters of A-Z a-z 0-9 _ - .");
        }
        if ($prefix !== null && !ToolNameValidator::isValidPrefix($prefix)) {
            throw new InvalidArgumentException("Invalid WebMCP name prefix [{$prefix}]: use at most 64 characters of A-Z a-z 0-9 _ - .");
        }
        if ($variablePattern !== null && @preg_match($variablePattern, '') === false) {
            throw new InvalidArgumentException("Invalid variablePattern regex [{$variablePattern}].");
        }
    }
}
