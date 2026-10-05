<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Attributes;

enum WebMcpMode: string
{
    case Bridge = 'bridge'; /** The browser calls the existing Mcp::web() endpoint via JSON-RPC. */
    case Session = 'session'; /** The browser calls routes registered by this package, executed in-process. */
    case Inherit = 'inherit'; /** Take the mode from the next level (server attribute, then config). */
}
