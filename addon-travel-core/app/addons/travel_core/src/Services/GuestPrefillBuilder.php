<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Builds the guest_prefill map the shared guest cards
 * (booking_guest_room_body.tpl) consume in edit mode — keys match the input
 * names exactly ("room{N}_{adult|child}_{i}" => last_name/first_name/dob).
 *
 * One implementation for both providers' edit_booking controllers: the
 * stored guests_data arrives as a JSON string, a canonical keyed map or an
 * indexed row list — GuestDataNormalizer resolves the shape — and DOBs fall
 * back to the stored birthday (YYYY-MM-DD → DD/MM/YYYY, the mask format the
 * form renders).
 */
final class GuestPrefillBuilder
{
    /**
     * @param array<array-key, mixed>|string $guestsData Stored guests_data (JSON string or array)
     * @return array<string, array{last_name: string, first_name: string, dob: string}>
     */
    public static function build(array|string $guestsData): array
    {
        // The normalizer's docblock says string-keyed, but it detects and
        // re-keys indexed row lists itself — route them through JSON so the
        // declared contract holds either way.
        $normalized = (new GuestDataNormalizer())->normalize(
            is_array($guestsData) ? (string) json_encode($guestsData) : $guestsData,
        );

        $prefill = [];
        foreach ($normalized as $key => $guest) {
            if (!is_array($guest)) {
                continue;
            }

            $dob = TypeCoerce::toString($guest['dob'] ?? '');
            if ($dob === '') {
                $birthday = TypeCoerce::toString($guest['birthday'] ?? '');
                if ($birthday !== '') {
                    $ts = strtotime($birthday);
                    if ($ts !== false && $ts !== 0) {
                        $dob = date('d/m/Y', $ts);
                    }
                }
            }

            $prefill[(string) $key] = [
                'last_name' => TypeCoerce::toString($guest['last_name'] ?? ''),
                'first_name' => TypeCoerce::toString($guest['first_name'] ?? ''),
                'dob' => $dob,
            ];
        }

        return $prefill;
    }
}
