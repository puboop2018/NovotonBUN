<?php

declare(strict_types=1);

/**
 * Travel Group Resolver
 *
 * Derives travel group classifications from facility canonical codes.
 * Travel groups (adults_only, family_friendly, pets_friendly) are NOT
 * raw API values — they're inferred from a hotel's facilities and flags.
 *
 * Single source of truth: both Novoton and Sphinx addons delegate here
 * instead of duplicating the derivation rules.
 *
 * @package TravelCore
 * @since   1.3.0
 */

namespace Tygh\Addons\TravelCore\Services;

final class TravelGroupResolver
{
    /** Facility canonical codes that indicate a family-friendly hotel */
    private const FAMILY_CODES = [
        'family_rooms',
        'kids_menu',
        'babysitting',
        'kids_club',
        'kids_pool',
        'playground',
    ];

    /** Facility canonical codes that indicate a pets-friendly hotel */
    private const PETS_CODES = [
        'pets_allowed',
    ];

    /**
     * Derive travel group canonical codes from resolved facility codes and hotel flags.
     *
     * @param string[] $facilityCodes Resolved canonical facility codes (e.g. ['free_wifi', 'pets_allowed', 'pool'])
     * @param bool $isAdultsOnly Whether the hotel is flagged as adults-only
     * @return string[] Canonical travel group codes (e.g. ['pets_friendly'])
     */
    public static function derive(array $facilityCodes, bool $isAdultsOnly = false): array
    {
        $groups = [];

        if ($isAdultsOnly) {
            $groups[] = 'adults_only';
        }

        if (!empty(array_intersect($facilityCodes, self::FAMILY_CODES))) {
            $groups[] = 'family_friendly';
        }

        if (!empty(array_intersect($facilityCodes, self::PETS_CODES))) {
            $groups[] = 'pets_friendly';
        }

        return $groups;
    }
}
