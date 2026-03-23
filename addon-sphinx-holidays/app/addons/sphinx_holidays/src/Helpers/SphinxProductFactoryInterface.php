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
     * Handles dedup check, category creation, product creation,
     * multi-language descriptions, feature assignment, and hotel linking.
     *
     * @param array  $hotel     Hotel row from sphinx_hotels
     * @param array  $hierarchy Resolved hierarchy: ['city' => ..., 'region' => ..., 'country' => ...]
     * @param string $template  Category path template with {country}/{region}/{city} placeholders
     * @return array{status: string, product_id: int, reason: string} Status is 'added', 'linked', 'skipped', or 'failed'
     */
    public function createFromHotel(array $hotel, array $hierarchy, string $template): array;

    /**
     * Build a category path from hotel data and hierarchy.
     *
     * @param array  $hotel     Hotel row
     * @param array  $hierarchy Resolved hierarchy
     * @param string $template  Category path template
     * @return string Resolved category path (e.g. "Hotels/Greece/Crete/Heraklion")
     */
    public function buildCategoryPath(array $hotel, array $hierarchy, string $template): string;
}
