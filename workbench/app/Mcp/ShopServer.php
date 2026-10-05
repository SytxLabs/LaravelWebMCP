<?php

declare(strict_types=1);

namespace Workbench\App\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Workbench\App\Mcp\Resources\CatalogResource;
use Workbench\App\Mcp\Resources\ProductResource;
use Workbench\App\Mcp\Tools\PlaceOrderTool;
use Workbench\App\Mcp\Tools\SearchProductsTool;

#[Instructions('Search the catalog, read products and (when logged in) place orders.')]
#[Name('Shop')]
class ShopServer extends Server
{
    protected array $tools = [
        SearchProductsTool::class,
        PlaceOrderTool::class,
    ];

    protected array $resources = [
        CatalogResource::class,
        ProductResource::class,
    ];
}
