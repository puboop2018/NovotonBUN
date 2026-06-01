<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

/**
 * Cron command: migrate existing SPX-prefixed product codes to country-code prefix.
 *
 * Finds every CS-Cart product linked in sphinx_hotels that still has an `SPX`
 * prefix and renames it to `{COUNTRY_CODE}{hotel_id}` (e.g. SPX59843 → HR59843).
 *
 * Run once after deploying the country-code prefix change. Safe to re-run —
 * products already on a CC prefix are counted as "already migrated" and skipped.
 *
 * Usage:
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=update_product_codes
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=update_product_codes&dry_run=1
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=update_product_codes&country=HR
 */
class UpdateProductCodesCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getDescription(): string
    {
        return 'Migrate SPX-prefixed product codes to country-code prefix (e.g. SPX59843 → HR59843)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $dryRun = !empty($params['dry_run']);
        $rawCountry = $params['country'] ?? '';
        $filterCountry = strtoupper(is_string($rawCountry) ? $rawCountry : '');

        if ($dryRun) {
            $this->output('DRY RUN — no changes will be written.');
        }

        $stats = [
            'scanned' => 0,
            'updated' => 0,
            'already_migrated' => 0,
            'no_country_code' => 0,
            'errors' => 0,
        ];

        // Load all hotels that have a linked product with an SPX-prefixed code
        $query = 'SELECT h.hotel_id, h.country_code, h.product_id, p.product_code
                  FROM ?:sphinx_hotels h
                  JOIN ?:products p ON p.product_id = h.product_id
                  WHERE p.product_code LIKE ?l';
        $args = ['SPX%'];

        if ($filterCountry !== '') {
            $query .= ' AND h.country_code = ?s';
            $args[] = $filterCountry;
        }

        /** @var array<array{hotel_id: string, country_code: string, product_id: string, product_code: string}> $rows */
        $rows = db_get_array($query, ...$args);

        $stats['scanned'] = count($rows);
        $this->output(sprintf('Found %d product(s) with SPX prefix to process.', $stats['scanned']));

        foreach ($rows as $row) {
            $hotelId = $row['hotel_id'];
            $cc = strtoupper($row['country_code']);
            $productId = (int) $row['product_id'];
            $oldCode = $row['product_code'];

            if ($cc === '') {
                $this->output("  SKIP  hotel_id={$hotelId} product_id={$productId}: no country_code");
                $stats['no_country_code']++;
                continue;
            }

            $newCode = $cc . $hotelId;

            if ($dryRun) {
                $this->output("  DRY   {$oldCode} → {$newCode}  (product_id={$productId})");
                $stats['updated']++;
                continue;
            }

            try {
                db_query(
                    'UPDATE ?:products SET product_code = ?s WHERE product_id = ?i',
                    $newCode,
                    $productId,
                );
                $this->output("  OK    {$oldCode} → {$newCode}  (product_id={$productId})");
                $stats['updated']++;
            } catch (\Throwable $e) {
                $this->output("  ERROR hotel_id={$hotelId}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        $this->output('');
        $this->output(sprintf(
            'Done: %d scanned, %d %s, %d already migrated, %d no country_code, %d errors.',
            $stats['scanned'],
            $stats['updated'],
            $dryRun ? 'would update' : 'updated',
            $stats['already_migrated'],
            $stats['no_country_code'],
            $stats['errors'],
        ));

        return ['success' => $stats['errors'] === 0, 'stats' => $stats];
    }
}
