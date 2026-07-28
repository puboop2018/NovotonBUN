<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Audits the hotel ⇄ product links that make a hotel page bookable.
 *
 * A sphinx hotel product page resolves through ?:sphinx_hotels.product_id:
 * no row pointing at the product means no booking form and no location line,
 * and the product renders as a plain catalog item. This service names every
 * product in that state and WHY, so the admin can see what the Relink action
 * (HotelSyncService::relinkExistingProducts) will repair before running it.
 *
 * Row states:
 *   missing        – no ?:sphinx_hotels row for the id in the product code;
 *                    relink fetches the hotel from the API and inserts it
 *   unlinked       – the row exists with no product_id; relink links it (so
 *                    does the first product-page view, via the provider's
 *                    code-based self-heal)
 *   linked_to_other– the row points at a DIFFERENT product; a human has to
 *                    decide which product is the real one
 */
final class ProductLinkAuditor
{
    public function __construct(private readonly HotelRepository $hotels)
    {
    }

    /**
     * @return array{total: int, rows: list<array{product_id: int, product_code: string, name: string, hotel_id: string, state: string, linked_product_id: int}>}
     */
    public function report(string $legacyPrefix, int $limit = 500): array
    {
        $total = $this->hotels->countUnlinkedProducts($legacyPrefix);
        $products = $this->hotels->findUnlinkedProducts($legacyPrefix, $limit);

        $rows = [];
        foreach ($products as $product) {
            $code = TypeCoerce::toString($product['product_code'] ?? '');
            $hotelId = self::hotelIdFromCode($code, $legacyPrefix);

            $state = 'missing';
            $linkedProductId = 0;
            if ($hotelId !== '') {
                $row = $this->hotels->findById($hotelId);
                if ($row !== null) {
                    $linkedProductId = TypeCoerce::toInt($row['product_id'] ?? 0);
                    $state = $linkedProductId > 0 ? 'linked_to_other' : 'unlinked';
                }
            }

            $rows[] = [
                'product_id' => TypeCoerce::toInt($product['product_id'] ?? 0),
                'product_code' => $code,
                'name' => TypeCoerce::toString($product['name'] ?? ''),
                'hotel_id' => $hotelId,
                'state' => $state,
                'linked_product_id' => $linkedProductId,
            ];
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * The hotel id inside a product code: the legacy configured prefix, else
     * the ISO-2 country prefix SphinxProductFactory writes (TR3612 → 3612).
     */
    public static function hotelIdFromCode(string $code, string $legacyPrefix): string
    {
        if ($legacyPrefix !== '' && str_starts_with($code, $legacyPrefix)) {
            return substr($code, strlen($legacyPrefix));
        }

        return (string) preg_replace('/^[A-Z]{2}/', '', $code);
    }
}
