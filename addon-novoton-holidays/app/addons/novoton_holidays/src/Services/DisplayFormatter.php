<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;

/**
 * Pure display formatting for novoton storefront surfaces — board labels,
 * provider free-text localization, hotel display names/titles, dates and
 * prices. Bodies extracted from functions/formatting.php; the fn_* shells
 * there delegate here and keep supplying the framework-bound inputs
 * (Registry date format, CART_LANGUAGE) as parameters, so every method is
 * unit-testable without CS-Cart.
 */
final class DisplayFormatter
{
    /**
     * Format a date using a CS-Cart strftime-style format string.
     *
     * With no explicit format, follows Settings ▸ Appearance ▸ date format —
     * the same single formatter every other customer-facing date in the travel
     * addons uses, so a store cannot end up showing two formats on one page.
     *
     * @param string|int $date Date string or timestamp
     */
    public static function formatDate(string|int $date, string $csFormat = ''): string
    {
        if (empty($date)) {
            return '';
        }

        return $csFormat === ''
            ? DateHelper::formatStoreDate($date)
            : DateHelper::formatWith($date, $csFormat);
    }

    /**
     * Localized board label: "Demipensiune (HB +)" — the Romanian meal-plan
     * name with the raw provider code in parens, the same format the search
     * card shows.
     *
     * Unknown codes return the RAW code BARE (no parens): booking_form.php
     * detects "formatter didn't change it" via strict equality to trigger its
     * board_name fallback, and "XYZ (XYZ)" would be noise.
     *
     * @param string $boardId Board code as received (e.g. "HB +", "AI", "ALL INCL")
     */
    public static function boardLabel(string $boardId): string
    {
        $raw = trim($boardId);
        if ($raw === '') {
            return '';
        }

        $names = [
            'AI' => 'All Inclusive',
            'ALLINC' => 'All Inclusive',
            'ALLINCL' => 'All Inclusive',
            'ALLINCLUSIVE' => 'All Inclusive',
            'AIL' => 'All Inclusive Light',
            'ALLINCLUSIVELIGHT' => 'All Inclusive Light',
            'ALLINCLUSIVESOFT' => 'All Inclusive Light',
            'UAI' => 'Ultra All Inclusive',
            'ULTRAALLINCL' => 'Ultra All Inclusive',
            'ULTRAALLINCLUSIVE' => 'Ultra All Inclusive',
            'FB' => 'Pensiune Completă',
            'FB+' => 'Pensiune Completă',
            'FULLBOARD' => 'Pensiune Completă',
            'HB' => 'Demipensiune',
            'HB+' => 'Demipensiune',
            'HALFBOARD' => 'Demipensiune',
            'BB' => 'Mic Dejun Inclus',
            'B&B' => 'Mic Dejun Inclus',
            'BEDANDBREAKFAST' => 'Mic Dejun Inclus',
            'RO' => 'Doar Cameră',
            'ROOMONLY' => 'Doar Cameră',
            'SC' => 'Self Catering',
            'SELFCATERING' => 'Self Catering',
        ];

        $key = strtoupper(str_replace(' ', '', $raw));
        $name = $names[$key] ?? null;

        if ($name === null) {
            return $raw;
        }

        return $name === $raw ? $name : $name . ' (' . $raw . ')';
    }

    /**
     * Resolve the storefront display language for provider-text localization:
     * explicit override → storefront language → 'ro' (the store's primary).
     */
    public static function resolveLang(?string $override, ?string $cartLanguage = null): string
    {
        if ($override !== null && $override !== '') {
            return strtolower($override);
        }
        if ($cartLanguage !== null && $cartLanguage !== '') {
            return strtolower($cartLanguage);
        }

        return 'ro';
    }

    /**
     * Localize the provider "MoreInfo" free-text (exact-match map, board_label
     * idiom): known English phrases map to Romanian, anything unrecognized
     * passes through UNCHANGED. RO storefront only.
     *
     * @param string $lang RESOLVED storefront language (see resolveLang())
     */
    public static function moreInfoLabel(string $text, string $lang): string
    {
        if ($lang !== 'ro') {
            return $text;
        }

        $raw = trim($text);
        if ($raw === '') {
            return '';
        }

        $map = [
            'beach included' => 'Acces la plajă',
        ];

        $key = strtolower(trim((string) preg_replace('/\s+/', ' ', $raw)));

        return $map[$key] ?? $raw;
    }

    /**
     * Localize recurring English phrases in the provider "remark" free-text
     * ("MIN 2 NIGHTS STAY in …" → "Minim 2 nopți de cazare în perioada …"),
     * leaving the rest (package names, dates) intact. RO storefront only.
     *
     * @param string $lang RESOLVED storefront language (see resolveLang())
     */
    public static function formatRemark(string $remark, string $lang): string
    {
        if ($remark === '' || $lang !== 'ro') {
            return $remark;
        }

        return (string) preg_replace(
            '/\bMIN\s+(\d+)\s+NIGHTS?\s+STAY\s+in\b/i',
            'Minim $1 nopți de cazare în perioada',
            $remark,
        );
    }

    /**
     * Normalize resort/city name for comparison ("ST.CONSTANTINE & ELENA" and
     * "ST. CONSTANTINE AND ELENA" normalize identically).
     */
    public static function normalizeResortName(string $name): string
    {
        $name = strtoupper(trim($name));
        $name = str_replace('&', 'AND', $name);
        $name = str_replace(['.', ',', '-', "'", '"'], ' ', $name);
        $name = (string) preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    /**
     * Format hotel display name: Title Case + append property type for short
     * names (1-2 words without a type keyword).
     *
     * @param string $detected_property_type Property type code from PropertyTypeDetector (e.g. 'hotel')
     */
    public static function formatHotelDisplayName(string $hotel_name, string $detected_property_type = '', ?PropertyTypeDetector $detector = null): string
    {
        $name = trim($hotel_name);
        if ($name === '') {
            return $name;
        }

        // Step 1: Title Case
        $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');

        // Fix common patterns after Title Case (Roman numerals, &)
        $name = str_replace([' & ', ' And '], ' & ', $name);
        // Restore common Roman numerals that Title Case lowercased
        $name = (string) preg_replace_callback('/\b(Ii|Iii|Iv|Vi|Vii|Viii|Ix)\b/', static fn (array $m): string => strtoupper($m[1]), $name);

        // Step 2: name already contains a type keyword (Hotel, Resort, …) — done
        $detector ??= new PropertyTypeDetector();
        if ($detector->detectFromName($name) !== null) {
            return $name;
        }

        // Step 3: 3+ words — keep as-is
        $word_count = count(preg_split('/\s+/', $name) ?: []);
        if ($word_count >= 3) {
            return $name;
        }

        // Step 4: 1-2 words, append the property type
        if (empty($detected_property_type)) {
            $detected_property_type = 'hotel';
        }

        $type_labels = [
            'hotel' => 'Hotel',
            'motel' => 'Motel',
            'hostel' => 'Hostel',
            'villa' => 'Villa',
            'apartment' => 'Apartments',
            'boarding_house' => 'Boarding House',
            'cabin' => 'Cabin',
            'chalet' => 'Chalet',
            'guest_house' => 'Guest House',
            'resort' => 'Resort',
        ];

        $label = $type_labels[$detected_property_type] ?? 'Hotel';

        return $name . ' ' . $label;
    }

    /**
     * Build the standard "Name - City, Country YEAR" hotel title.
     */
    public static function buildHotelTitle(string $hotel_name, string $city, string $country, string|int $year, ?PropertyTypeDetector $detector = null): string
    {
        // Idempotent display-name formatting (type-keyword names pass through).
        $hotel_name = self::formatHotelDisplayName(trim($hotel_name), '', $detector);

        $location_parts = [];
        if ($city !== '') {
            $location_parts[] = ucwords(strtolower(trim($city)));
        }
        if ($country !== '') {
            $location_parts[] = ucwords(strtolower(trim($country)));
        }

        $location = implode(', ', $location_parts);

        $title = $hotel_name;
        if ($location !== '') {
            $title .= ' - ' . $location;
        }
        if (!empty($year)) {
            $title .= ' ' . $year;
        }

        return $title;
    }

    /**
     * Format a price for display: coefficient conversion, dot thousands
     * separator, decimals as a <sup> tag — or a plain integer when the
     * "Round hotel prices" setting is on.
     *
     * @param float|string $amount Raw price in primary currency
     * @param float|string $coefficient Currency conversion coefficient
     * @param string $symbol Currency symbol/code to append
     * @param bool|null $roundPrices Override for tests; null reads the addon setting
     */
    public static function formatPrice(float|string $amount, float|string $coefficient = 1.0, string $symbol = '', ?bool $roundPrices = null): string
    {
        $display_price = TypeCoerce::toFloat($amount) * TypeCoerce::toFloat($coefficient);

        // When round_prices is enabled, always display as integer (no decimals)
        $shouldRound = $roundPrices ?? ConfigProvider::isRoundPrices();

        if ($shouldRound) {
            $formatted = number_format(round($display_price), 0, '', '.');
        } else {
            // Meaningful decimals = more than 0.005 away from nearest integer
            $rounded = round($display_price);
            $has_decimals = abs($display_price - $rounded) >= 0.005;

            if ($has_decimals) {
                $integer_part = (int) floor($display_price);
                $decimal_part = (int) round(($display_price - $integer_part) * 100);
                $formatted_integer = number_format($integer_part, 0, '', '.');
                $formatted = $formatted_integer . '<sup class="price-decimal">' . str_pad((string) $decimal_part, 2, '0', STR_PAD_LEFT) . '</sup>';
            } else {
                $formatted = number_format($rounded, 0, '', '.');
            }
        }

        if ($symbol !== '') {
            $formatted .= ' ' . $symbol;
        }

        return $formatted;
    }
}
