<?php

declare(strict_types=1);

namespace Workbench\App;

/**
 * A tiny in-memory catalog so the example needs no database.
 */
final class Catalog
{
    /**
     * @return list<array{id: int, name: string, category: string, price: float, in_stock: bool}>
     */
    public static function all(): array
    {
        return [
            ['id' => 1, 'name' => 'Trail Runner', 'category' => 'shoes', 'price' => 89.9, 'in_stock' => true],
            ['id' => 2, 'name' => 'City Sneaker', 'category' => 'shoes', 'price' => 59.0, 'in_stock' => false],
            ['id' => 3, 'name' => 'Wool Hat', 'category' => 'hats', 'price' => 24.5, 'in_stock' => true],
            ['id' => 4, 'name' => 'Sun Hat', 'category' => 'hats', 'price' => 19.0, 'in_stock' => true],
        ];
    }

    /**
     * @return array{id: int, name: string, category: string, price: float, in_stock: bool}|null
     */
    public static function find(int $id): ?array
    {
        foreach (self::all() as $product) {
            if ($product['id'] === $id) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: int, name: string, category: string, price: float, in_stock: bool}>
     */
    public static function search(string $query, ?string $category, ?float $maxPrice, bool $inStock): array
    {
        return array_values(array_filter(self::all(), fn (array $p): bool => stripos($p['name'], $query) !== false
            && ($category === null || $category === '' || $p['category'] === $category)
            && ($maxPrice === null || $p['price'] <= $maxPrice)
            && (!$inStock || $p['in_stock'])));
    }
}
