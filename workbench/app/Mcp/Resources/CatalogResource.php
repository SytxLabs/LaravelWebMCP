<?php

declare(strict_types=1);

namespace Workbench\App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;
use SytxLabs\LaravelWebMcp\Attributes\WebMcp;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpMode;
use Workbench\App\Catalog;

/**
 * A static resource becomes the read-only tool "read-catalog-resource" (untrusted content hint included).
 * Bridge mode: the browser reads it through the Mcp::web() endpoint (guarded by EnforceWebMcpExposure).
 */
#[Description('The complete product catalog as JSON.')]
#[Uri('shop://catalog')]
#[WebMcp(mode: WebMcpMode::Bridge)]
class CatalogResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::json(Catalog::all());
    }
}
