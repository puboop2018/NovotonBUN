<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Contracts;

/**
 * Contract for the Sphinx feature assigner.
 *
 * Assigns CS-Cart product features (stars, board, property type, facilities,
 * travel group, location) from sphinx_hotels data.
 */
interface SphinxFeatureAssignerInterface
{
    /**
     * Assign all features from a sphinx_hotels row to a CS-Cart product.
     *
     * @param int $productId CS-Cart product ID
     * @param array<string, mixed> $hotel Row from sphinx_hotels table
     */
    public function assignAll(int $productId, array $hotel): void;
}
