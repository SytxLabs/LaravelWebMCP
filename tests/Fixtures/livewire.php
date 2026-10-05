<?php

declare(strict_types=1);

// Livewire fixtures; required from tests/Pest.php when Livewire is installed.

namespace SytxLabs\LaravelWebMcp\Tests\Fixtures;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use SytxLabs\LaravelWebMcp\Attributes\WebMcpAction;
use SytxLabs\LaravelWebMcp\Livewire\ExposesWebMcpActions;

enum Priority: string
{
    case Low = 'low';
    case High = 'high';
}

enum Level: int
{
    case One = 1;
    case Two = 2;
}

enum PlainEnum
{
    case A;
}

class Product extends Model
{
}

class CartComponent extends Component
{
    use ExposesWebMcpActions;

    public ?int $productId = null;

    #[WebMcpAction(description: 'Adds a product to the cart', parameters: ['qty' => 'How many'])]
    public function addToCart(int $productId, int $qty = 1): string
    {
        return "added {$qty} of {$productId}";
    }

    #[WebMcpAction(description: 'Empties the cart', consequential: true)]
    public function clear(): void
    {
    }

    #[WebMcpAction(description: 'Lists the cart', readOnly: true, name: 'cart.list')]
    public function items(): array
    {
        return ['a', 'b'];
    }

    public function notExposed(): void
    {
    }

    public function render(): string
    {
        return '<div>cart @webmcpActions</div>';
    }
}

class TypesComponent extends Component
{
    #[WebMcpAction(description: 'Type matrix')]
    public function types(
        int $i,
        float $f,
        string $s,
        bool $b,
        array $a,
        Priority $priority,
        Level $level,
        ?string $nullable,
        int|string $union,
        mixed $any,
        string $withDefault = 'x',
        array $list = ['a'],
        ?int $maybe = null,
        int ...$rest,
    ): void {
    }

    #[WebMcpAction(description: 'Model param')]
    public function view(Product $product): void
    {
    }

    #[WebMcpAction(description: 'Custom schema', schema: ['properties' => ['q' => ['type' => 'string', 'minLength' => 2]], 'required' => ['q']])]
    public function search(string $q, mixed $extra = null): void
    {
    }

    public function render(): string
    {
        return '<div>types</div>';
    }
}

class UnmappableComponent extends Component
{
    #[WebMcpAction(description: 'Takes an object')]
    public function take(DateTimeInterface $when): void
    {
    }

    public function render(): string
    {
        return '<div>x</div>';
    }
}

class PureEnumComponent extends Component
{
    #[WebMcpAction(description: 'Pure enum')]
    public function take(PlainEnum $e): void
    {
    }

    public function render(): string
    {
        return '<div>x</div>';
    }
}

class LifecycleComponent extends Component
{
    #[WebMcpAction(description: 'Nope')]
    public function mount(): void
    {
    }

    public function render(): string
    {
        return '<div>x</div>';
    }
}

class UpdatedHookComponent extends Component
{
    public string $title = '';

    #[WebMcpAction(description: 'Nope')]
    public function updatedTitle(): void
    {
    }

    public function render(): string
    {
        return '<div>x</div>';
    }
}

class KeyedComponent extends Component
{
    use ExposesWebMcpActions;

    public int $id = 1;

    public function webMcpInstanceKey(): ?string
    {
        return 'Row '.$this->id;
    }

    #[WebMcpAction(description: 'Archives the row')]
    public function archive(): void
    {
    }

    public function render(): string
    {
        return '<div>row @webmcpActions</div>';
    }
}

class GuardedComponent extends Component
{
    use ExposesWebMcpActions;

    public bool $admin = false;

    public function webMcpAvailable(string $method): bool
    {
        return $method !== 'purge' || $this->admin;
    }

    #[WebMcpAction(description: 'Open to everyone')]
    public function look(): void
    {
    }

    #[WebMcpAction(description: 'Admins only')]
    public function purge(): void
    {
    }

    public function render(): string
    {
        return '<div>guarded @webmcpActions</div>';
    }
}

class HostileActionComponent extends Component
{
    #[WebMcpAction(description: "</script><script>alert(1)</script> {{ 1 + 1 }} & 'q'", name: 'hostile.action')]
    public function go(): void
    {
    }

    public function render(): string
    {
        return '<div>@webmcpActions</div>';
    }
}

class EmptyComponent extends Component
{
    public function nothing(): void
    {
    }

    public function render(): string
    {
        return '<div>empty @webmcpActions</div>';
    }
}

class ValidatingComponent extends Component
{
    #[WebMcpAction(description: 'Saves a quantity')]
    public function save(int $qty): string
    {
        // A custom validator: Livewire does not persist its errors, it swallows the exception.
        Validator::make(['qty' => $qty], ['qty' => ['integer', 'max:5']])->validate();

        return 'saved '.$qty;
    }

    public function render(): string
    {
        return '<div>validating</div>';
    }
}

class PlainValidatingComponent extends Component
{
    public function save(int $qty): string
    {
        Validator::make(['qty' => $qty], ['qty' => ['integer', 'max:5']])->validate();

        return 'saved';
    }

    public function render(): string
    {
        return '<div>plain</div>';
    }
}
