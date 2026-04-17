<?php

declare(strict_types=1);

/**
 * Variant Resolver Interface
 *
 * Contract for resolving and auto-creating CS-Cart product feature variants
 * for feature mapping rows.
 *
 * @package TravelCore
 * @since   1.3.0
 */

namespace Tygh\Addons\TravelCore\Contracts;

interface VariantResolverInterface
{
    /**
     * Ensure a CS-Cart variant exists for a mapping row.
     *
     * @param array<string, mixed> $mapping
     * @return int variant_id or 0
     */
    public function ensureVariantExists(array $mapping): int;

    /**
     * 3-pass variant name matching (exact → case-insensitive → normalized).
     *
     * @param array<string, mixed> $mapping
     * @return int Matched variant_id or 0
     */
    public function findVariantByName(array $mapping, int $featureId): int;
}
