<?php

declare(strict_types=1);

namespace Workbench\App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use SytxLabs\LaravelWebMcp\Attributes\WebMcp;
use Workbench\App\Catalog;

/**
 * Read-only: safe to auto-submit as a declarative form (see the search form on the start page).
 */
#[Description('Search the shop catalog by text and optional category or maximum price.')]
#[IsReadOnly]
#[WebMcp]
class SearchProductsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('What to search for')->min(2)->max(50)->required(),
            'category' => $schema->string()->enum(['shoes', 'hats'])->description('Only this category'),
            'max_price' => $schema->number()->min(0)->description('Maximum price in EUR'),
            'in_stock' => $schema->boolean()->description('Only items that are in stock'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:50'],
            'category' => ['nullable', 'in:shoes,hats'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'in_stock' => ['nullable', 'boolean'],
        ]);

        $found = Catalog::search(
            (string) $request->get('query'),
            $request->get('category'),
            $request->get('max_price') !== null ? (float) $request->get('max_price') : null,
            (bool) $request->get('in_stock', false),
        );

        return Response::structured(['count' => count($found), 'products' => $found]);
    }
}
