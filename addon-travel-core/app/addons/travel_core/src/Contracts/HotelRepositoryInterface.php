<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Shared, provider-neutral contract for a hotel repository.
 *
 * Each travel provider (Novoton, Sphinx, …) stores hotels in its own table
 * with a divergent schema, so the bulk of a provider's repository is
 * provider-specific and stays in the provider addon. This interface captures
 * only the operations that are universal to *any* hotel repository — fetching
 * a single hotel row by its provider hotel id, existence/deletion, the
 * product↔hotel link, and the list of countries — so that travel_core code
 * (and cross-provider tooling) can depend on the abstraction rather than a
 * concrete class.
 *
 * Provider addons extend this with their own richer interface (search, price
 * sync, packages, reporting, …). Rows are returned as associative arrays
 * keyed by column name; the exact columns are provider-specific.
 *
 * @package TravelCore
 * @since   1.0.0
 */
interface HotelRepositoryInterface
{
    /**
     * Fetch a single hotel row by its provider hotel id.
     *
     * @return array<string, mixed>|null Null when no row matches.
     */
    public function findById(string $hotelId): ?array;

    /**
     * Whether a hotel row exists for the given provider hotel id.
     */
    public function exists(string $hotelId): bool;

    /**
     * Delete a hotel (and any tightly-owned child rows) by hotel id.
     *
     * @return bool True on success.
     */
    public function delete(string $hotelId): bool;

    /**
     * Associate a hotel with a CS-Cart product.
     *
     * @return bool True on success.
     */
    public function linkToProduct(string $hotelId, int $productId): bool;

    /**
     * Remove the hotel↔product association for the given product.
     *
     * @return bool True on success.
     */
    public function unlinkProduct(int $productId): bool;

    /**
     * Distinct list of countries that have at least one hotel.
     *
     * @return list<string>
     */
    public function getCountries(): array;
}
