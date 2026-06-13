<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Reconciles the hotel <-> CS-Cart product links in novoton_hotels.
 *
 * Two passes, run once per fresh hotelinfo sync:
 *   1. Re-link hotels with a NULL product_id whose product (by configured
 *      product_code prefix) actually exists.
 *   2. Clear product_id pointers that reference a deleted product.
 *
 * Extracted from BatchedHotelInfoSyncV2 so this DB-maintenance concern is a
 * standalone, testable collaborator. SQL is preserved verbatim.
 */
class HotelProductLinkReconciler
{
    /**
     * @param string[] $productCodePrefixes Configured product-code prefixes (e.g. ['NVT', 'NV'])
     */
    public function __construct(
        private readonly SyncLoggerInterface $logger,
        private readonly array $productCodePrefixes,
    ) {
    }

    public function reconcile(): void
    {
        $prefixes = $this->productCodePrefixes;

        // 1. Re-link: hotels with NULL product_id whose product exists
        $orphaned = TypeCoerce::toStringList(db_get_fields(
            'SELECT hotel_id FROM ?:novoton_hotels WHERE product_id IS NULL OR product_id = 0',
        ));

        $linked = 0;
        if ($orphaned !== []) {
            // Build all possible product_codes in one go, then bulk-fetch
            $codePatterns = [];
            foreach ($orphaned as $hotelId) {
                foreach ($prefixes as $prefix) {
                    $codePatterns[] = $prefix . $hotelId;
                }
            }

            $productMap = [];
            if (!empty($codePatterns)) {
                $productMap = TypeCoerce::toStringMap(db_get_hash_single_array(
                    'SELECT product_code, product_id FROM ?:products WHERE product_code IN (?a)',
                    ['product_code', 'product_id'],
                    $codePatterns,
                ));
            }

            // Update matched hotels using the map (no per-hotel queries)
            foreach ($orphaned as $hotelId) {
                foreach ($prefixes as $prefix) {
                    $pid = $productMap[$prefix . $hotelId] ?? null;
                    if (!empty($pid)) {
                        db_query(
                            'UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s',
                            $pid,
                            $hotelId,
                        );
                        $linked++;
                        break;
                    }
                }
            }
        }

        // 2. Cleanup: clear product_id pointing to deleted products
        $cleaned = TypeCoerce::toInt(db_query(
            'UPDATE ?:novoton_hotels h
             LEFT JOIN ?:products p ON h.product_id = p.product_id
             SET h.product_id = NULL
             WHERE h.product_id > 0 AND p.product_id IS NULL',
        ));

        if ($linked > 0 || $cleaned > 0) {
            $this->logger->output("Reconciliation: re-linked {$linked} hotels, cleared {$cleaned} stale references.");
        }
    }
}
