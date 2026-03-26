<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

/**
 * Cron command: detect and clean up duplicate hotels.
 *
 * The Sphinx API returns the same physical hotel under different IDs.
 * This command finds duplicate groups (same name + property_type + classification
 * + region_id + country_code), picks a canonical hotel for each group, and
 * links all duplicates to the canonical's CS-Cart product. Orphaned duplicate
 * products are deleted.
 *
 * Usage: index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=deduplicate
 *        index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=deduplicate&dry_run=1
 */
class DeduplicateCommand
{
    private ?\Closure $outputCallback = null;

    public static function getDescription(): string
    {
        return 'Detect and clean up duplicate hotels (same name/region from different supplier IDs)';
    }

    public function setOutputCallback(\Closure $callback): void
    {
        $this->outputCallback = $callback;
    }

    public function execute(array $params = []): array
    {
        $dryRun = !empty($params['dry_run']);
        $startMs = (int)(microtime(true) * 1000);

        $stats = [
            'groups'            => 0,
            'hotels_linked'     => 0,
            'products_removed'  => 0,
            'already_correct'   => 0,
        ];

        $this->output('Finding duplicate hotel groups...' . ($dryRun ? ' (DRY RUN — no changes)' : ''));

        // Find groups of hotels with same name + property_type + classification + region_id + country_code
        $groups = db_get_array(
            "SELECT name, property_type, classification, region_id, country_code,
                    COUNT(*) AS cnt,
                    GROUP_CONCAT(hotel_id ORDER BY hotel_id) AS hotel_ids
             FROM ?:sphinx_hotels
             WHERE sync_status = 'active'
             GROUP BY name, property_type, classification, region_id, country_code
             HAVING cnt > 1
             ORDER BY cnt DESC"
        );

        if (empty($groups)) {
            $this->output('No duplicate groups found.');
            return ['success' => true, 'stats' => $stats];
        }

        $this->output('Found ' . count($groups) . ' duplicate groups.');
        $stats['groups'] = count($groups);

        foreach ($groups as $group) {
            $hotelIds = explode(',', $group['hotel_ids']);
            $groupLabel = "\"{$group['name']}\" ({$group['country_code']}, region {$group['region_id']})";

            // Get full data for all hotels in this group
            $hotels = db_get_array(
                "SELECT hotel_id, product_id, name FROM ?:sphinx_hotels WHERE hotel_id IN (?a)",
                $hotelIds
            );

            // Pick canonical: prefer hotel with product_id (lowest hotel_id), then just lowest hotel_id
            $canonical = null;
            $withProduct = [];
            foreach ($hotels as $h) {
                $pid = (int) ($h['product_id'] ?? 0);
                if ($pid > 0) {
                    $withProduct[$h['hotel_id']] = $pid;
                }
            }

            if (!empty($withProduct)) {
                // Use lowest hotel_id that has a product
                ksort($withProduct);
                $canonicalId = array_key_first($withProduct);
                $canonicalProductId = $withProduct[$canonicalId];
            } else {
                // No hotel in group has a product yet — nothing to deduplicate
                $stats['already_correct']++;
                continue;
            }

            // Collect all distinct product_ids in this group (to find orphans)
            $distinctProducts = array_values(array_unique(array_filter(array_values($withProduct))));

            // Process non-canonical hotels
            foreach ($hotels as $h) {
                if ($h['hotel_id'] === $canonicalId) {
                    continue;
                }

                $hPid = (int) ($h['product_id'] ?? 0);

                if ($hPid === $canonicalProductId) {
                    // Already linked to the correct product — just mark as duplicate
                    if (!$dryRun) {
                        db_query(
                            "UPDATE ?:sphinx_hotels SET product_skip_reason = 'duplicate' WHERE hotel_id = ?s",
                            $h['hotel_id']
                        );
                    }
                    $stats['hotels_linked']++;
                    continue;
                }

                // Link to canonical's product
                if (!$dryRun) {
                    db_query(
                        "UPDATE ?:sphinx_hotels SET product_id = ?i, product_skip_reason = 'duplicate' WHERE hotel_id = ?s",
                        $canonicalProductId, $h['hotel_id']
                    );
                }
                $stats['hotels_linked']++;

                // If this hotel had a DIFFERENT product, check if that product is now orphaned
                if ($hPid > 0 && $hPid !== $canonicalProductId) {
                    $otherHotelsUsingProduct = (int) db_get_field(
                        "SELECT COUNT(*) FROM ?:sphinx_hotels
                         WHERE product_id = ?i AND hotel_id != ?s AND product_skip_reason IS NULL",
                        $hPid, $h['hotel_id']
                    );

                    if ($otherHotelsUsingProduct === 0) {
                        // Product is orphaned — delete it
                        if (!$dryRun && function_exists('fn_delete_product')) {
                            fn_delete_product($hPid);
                            $this->output("  [{$groupLabel}] Deleted orphan product #{$hPid}");
                        }
                        $stats['products_removed']++;
                    }
                }
            }

            if (count($distinctProducts) > 1) {
                $this->output("[{$group['cnt']}x] {$groupLabel}: canonical={$canonicalId} (product #{$canonicalProductId}), "
                    . ($stats['products_removed'] > 0 ? "removed orphan products" : "linked duplicates"));
            }
        }

        $durationMs = (int)(microtime(true) * 1000) - $startMs;
        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->output("{$prefix}Done: {$stats['groups']} groups, {$stats['hotels_linked']} hotels linked, "
            . "{$stats['products_removed']} orphan products removed, "
            . "{$stats['already_correct']} groups already correct ("
            . round($durationMs / 1000, 1) . "s)");

        return ['success' => true, 'stats' => $stats];
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}
