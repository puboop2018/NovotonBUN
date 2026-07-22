<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Services\HotelLocationLine;
use Tygh\Addons\TravelCore\Services\HotelMapUrl;

/**
 * Builds the shared hotel-header view model from a HotelSeoData DTO — the
 * ONE place the location line + map URL derivation and the VM assembly
 * happen. Before this existed, all four consuming surfaces (novoton +
 * sphinx, search + booking form) ran the same
 * HotelLocationLine::build -> HotelMapUrl::build -> new HotelHeaderViewModel
 * sequence themselves.
 */
final class HotelHeaderFactory
{
    /**
     * @param string $locationLineFallback Shown when the sanitizer produces
     *        nothing (e.g. the novoton booking form's raw "City, Region,
     *        Country" join). The map URL is always derived from the SANITIZED
     *        line, before the fallback applies — same as the old call sites.
     */
    public static function fromSeo(
        HotelSeoData $seo,
        int $stars,
        int $productId,
        string $locationLineFallback = '',
    ): HotelHeaderViewModel {
        $locationLine = HotelLocationLine::build($seo);
        $mapUrl = HotelMapUrl::build($seo, $locationLine) ?? '';
        if ($locationLine === '' && $locationLineFallback !== '') {
            $locationLine = $locationLineFallback;
        }

        return new HotelHeaderViewModel(
            name: $seo->name,
            stars: $stars,
            locationLine: $locationLine,
            mapUrl: $mapUrl,
            productId: $productId,
        );
    }

    /**
     * Header for a hotel with no repository row (no address/coordinates):
     * name + stars + product link only.
     */
    public static function bare(string $name, int $stars, int $productId): HotelHeaderViewModel
    {
        return new HotelHeaderViewModel(
            name: $name,
            stars: $stars,
            locationLine: '',
            mapUrl: '',
            productId: $productId,
        );
    }
}
