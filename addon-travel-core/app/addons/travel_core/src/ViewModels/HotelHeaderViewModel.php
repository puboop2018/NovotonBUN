<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

/**
 * The hotel-identity header shared by both providers' search results and
 * guest booking forms: name (linked to the product page when known), star
 * rating, sanitized location line and the always-present map link.
 *
 * Rendered by design/themes/... /addons/travel_core/components/hotel_header.tpl
 * from the `travel_hotel_header` view variable — one template, one variable
 * name, four consuming surfaces. Before this existed, each surface assembled
 * the same five fields under its own variable names and every header change
 * had to be applied four times (x2 themes).
 */
final class HotelHeaderViewModel
{
    public readonly string $name;
    public readonly int $stars;
    public readonly string $locationLine;
    public readonly string $mapUrl;
    public readonly int $productId;

    public function __construct(
        string $name,
        int $stars,
        string $locationLine,
        string $mapUrl,
        int $productId,
    ) {
        $this->name = trim($name) !== '' ? $name : 'Hotel';
        $this->stars = min(5, max(0, $stars));
        $this->locationLine = $locationLine;
        $this->mapUrl = $mapUrl;
        $this->productId = max(0, $productId);
    }

    /**
     * The Smarty-facing shape read by components/hotel_header.tpl.
     *
     * @return array{name: string, stars: int, location_line: string, map_url: string, product_id: int}
     */
    public function toViewArray(): array
    {
        return [
            'name' => $this->name,
            'stars' => $this->stars,
            'location_line' => $this->locationLine,
            'map_url' => $this->mapUrl,
            'product_id' => $this->productId,
        ];
    }
}
