<?php

declare(strict_types=1);

namespace Workbench\App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use SytxLabs\LaravelWebMcp\Attributes\WebMcp;
use Workbench\App\Catalog;

/**
 * Consequential: #[IsDestructive] becomes consequentialHint, the browser asks the user to confirm
 * (and the server can enforce a confirmation token: config webmcp.confirmation.server_enforced).
 * It is only offered to logged-in users, and the user sees it in the audit log.
 */
#[Description('Places a binding order for a product. The user must confirm this.')]
#[IsDestructive]
#[WebMcp]
class PlaceOrderTool extends Tool
{
    public function shouldRegister(Request $request): bool
    {
        return $request->user() !== null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->description('ID of the product')->required(),
            'quantity' => $schema->integer()->min(1)->max(5)->default(1)->description('How many'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $product = Catalog::find((int) $data['product_id']);

        if ($product === null || !$product['in_stock']) {
            return Response::error('That product is not available.');
        }

        return Response::text(sprintf('Ordered %d x %s.', $data['quantity'] ?? 1, $product['name']));
    }
}
