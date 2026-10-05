<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Contracts;

use Laravel\Mcp\Request;

/** Implement on a laravel/mcp Resource to authorize a concrete WebMCP read (IDOR protection). Called on every read with the already validated URI template variables. */
interface AuthorizesWebMcpRead
{
    /** @param  array<string, string>  $variables  URI template variables (empty for static resources). */
    public function authorizeWebMcpRead(Request $request, array $variables): bool;
}
