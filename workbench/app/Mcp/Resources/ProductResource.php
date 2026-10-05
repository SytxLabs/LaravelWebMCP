<?php

declare(strict_types=1);

namespace Workbench\App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;
use SytxLabs\LaravelWebMcp\Attributes\WebMcp;
use SytxLabs\LaravelWebMcp\Contracts\AuthorizesWebMcpRead;
use Workbench\App\Catalog;

/**
 * A template resource: {productId} becomes a required string argument of "read-product-resource".
 * AuthorizesWebMcpRead is called on every read with the validated variables (IDOR protection); the variables
 * are also checked against path traversal before this class is reached. `variablePattern` narrows them further.
 */
#[Description('One product by its numeric ID.')]
#[WebMcp(variablePattern: '/^\d{1,6}$/')]
class ProductResource extends Resource implements AuthorizesWebMcpRead, HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('shop://products/{productId}');
    }

    public function authorizeWebMcpRead(Request $request, array $variables): bool
    {
        return Catalog::find((int) $variables['productId']) !== null;
    }

    public function handle(Request $request): Response
    {
        return Response::json(Catalog::find((int) $request->get('productId')));
    }
}
