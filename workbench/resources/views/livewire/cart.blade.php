<div>
    @webmcpActions

    <h3>Cart (Livewire)</h3>
    <ul data-testid="cart-items">
        @forelse ($items as $id => $quantity)
            <li wire:key="item-{{ $id }}">{{ $quantity }} x {{ \Workbench\App\Catalog::find($id)['name'] ?? 'Unknown' }}</li>
        @empty
            <li>Empty</li>
        @endforelse
    </ul>
    <button type="button" wire:click="addToCart(1)">Add Trail Runner</button>
    <button type="button" wire:click="clear">Clear</button>
</div>
