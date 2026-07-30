<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Localized amenity labels for a hotel, from any provider.
 *
 * Both providers hand a customer-facing list of amenities to the booking
 * sidebar, and both had to answer the same question — "what is this facility
 * called in the shopper's language?" — but only sphinx asked it properly.
 * It resolves each provider facility id through ?:travel_api_alias into
 * ?:travel_feature_map, whose seed carries hand-written Romanian for all 129
 * canonical facility codes. Novoton instead read its own
 * novoton_facilities.facility_name_ro column, which its sync fills with a
 * verbatim copy of the ENGLISH name — so a Romanian storefront listed
 * "Air conditioning/Heating" and "Free umbrella and sunbed".
 *
 * Novoton's facility ids are already aliased to those same canonical codes
 * (NovotonAliasSeedData), so this is wiring, not translation work: both
 * providers now come through here and get the seeded Romanian.
 *
 * Unmapped facilities fall back to the provider's own label. English beats a
 * blank chip, and a silent drop (which is what an empty RO column used to
 * cause) is the worst of the three.
 */
final class FacilityLabelResolver
{
    /**
     * @param string $apiSource provider key in ?:travel_api_alias ('novoton', 'sphinx')
     * @param list<array{id: int|string, name?: string}> $facilities provider
     *                                                               facility ids with their raw provider names
     * @param string $lang storefront language code ('ro' selects the Romanian column)
     * @param int $limit the sidebar shows an at-a-glance strip, not the full
     *                   inventory — that belongs on the product page
     *
     * @return list<string>
     */
    public static function labels(string $apiSource, array $facilities, string $lang = 'en', int $limit = 6): array
    {
        $column = self::column($lang);
        $labels = [];

        foreach ($facilities as $facility) {
            $id = trim(TypeCoerce::toString($facility['id']));
            $label = '';

            if ($id !== '') {
                $mapping = FeatureMapper::resolveFacility($apiSource, $id);
                if (is_array($mapping)) {
                    $label = trim(TypeCoerce::toString($mapping[$column] ?? ''));
                    if ($label === '') {
                        // A mapping row exists but this language is blank —
                        // English is a better chip than nothing.
                        $label = trim(TypeCoerce::toString($mapping['display_name_en'] ?? ''));
                    }
                }
            }

            if ($label === '') {
                $label = trim(TypeCoerce::toString($facility['name'] ?? ''));
            }

            if ($label !== '' && !in_array($label, $labels, true)) {
                $labels[] = $label;
            }
            if (count($labels) >= $limit) {
                break;
            }
        }

        return $labels;
    }

    /** Romanian is the only translated column today; everything else is EN. */
    public static function column(string $lang): string
    {
        return strtolower(substr(trim($lang), 0, 2)) === 'ro' ? 'display_name_ro' : 'display_name_en';
    }
}
