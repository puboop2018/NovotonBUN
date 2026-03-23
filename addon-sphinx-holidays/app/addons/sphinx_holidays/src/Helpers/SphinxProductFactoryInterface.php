<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

/**
 * Contract for creating CS-Cart products from Sphinx hotel data.
 */
interface SphinxProductFactoryInterface
{
    /**
     * Create a CS-Cart product from a Sphinx hotel row.
     *
     * Category structure: Root Category (from settings) → Country (dynamic).
     * Region and City are assigned as product features, not categories.
     *
     * @param array $hotel     Hotel row from sphinx_hotels
     * @param array $hierarchy Resolved hierarchy: ['city' => ..., 'region' => ..., 'country' => ...]
     * @return array{status: string, product_id: int, reason: string} Status is 'added', 'linked', 'skipped', or 'failed'
     */
    public function createFromHotel(array $hotel, array $hierarchy): array;

    /**
     * Resolve the country name from hotel data and hierarchy.
     *
     * @param array $hotel     Hotel row
     * @param array $hierarchy Resolved hierarchy
     * @return string Country name, or empty string if unresolvable
     */
    public function resolveCountryName(array $hotel, array $hierarchy): string;
}
