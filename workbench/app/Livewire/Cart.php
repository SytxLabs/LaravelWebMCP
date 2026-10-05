<?php

declare(strict_types=1);

namespace Workbench\App\Livewire;

use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpAction;
use SytxLabs\LaravelWebMcp\Livewire\ExposesWebMcpActions;
use Workbench\App\Catalog;

/**
 * Livewire actions as WebMCP tools: the browser calls them with $wire.$call, so Livewire's validation,
 * checksum and your own authorization stay in charge. `@webmcpActions` in the view publishes them.
 */
class Cart extends Component
{
    use ExposesWebMcpActions;

    /** @var array<int, int> product id => quantity */
    public array $items = [];

    #[WebMcpAction(
        description: 'Adds a product to the shopping cart on this page.',
        parameters: ['productId' => 'ID of the product', 'qty' => 'How many (1-5)'],
    )]
    public function addToCart(int $productId, int $qty = 1): string
    {
        Validator::make(
            ['productId' => $productId, 'qty' => $qty],
            ['productId' => ['required', 'integer'], 'qty' => ['required', 'integer', 'min:1', 'max:5']],
        )->validate();

        $product = Catalog::find($productId);

        if ($product === null) {
            return 'Unknown product.';
        }

        $this->items[$productId] = ($this->items[$productId] ?? 0) + $qty;

        return sprintf('Added %d x %s. The cart now holds %d item(s).', $qty, $product['name'], array_sum($this->items));
    }

    #[WebMcpAction(description: 'Empties the shopping cart.', consequential: true)]
    public function clear(): string
    {
        $this->items = [];

        return 'The cart is empty.';
    }

    /**
     * @return list<array{product: string, quantity: int}>
     */
    #[WebMcpAction(description: 'Lists what is in the shopping cart.', readOnly: true, name: 'cart.list')]
    public function summary(): array
    {
        $lines = [];

        foreach ($this->items as $id => $quantity) {
            $lines[] = ['product' => Catalog::find($id)['name'] ?? 'Unknown', 'quantity' => $quantity];
        }

        return $lines;
    }

    public function render()
    {
        return view('workbench::livewire.cart');
    }
}
