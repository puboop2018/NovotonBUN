<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

class AuditFacilitiesCommand
{
    private ?\Closure $outputCallback = null;

    public static function getDescription(): string
    {
        return 'Audit unmapped facility IDs from Sphinx hotels (report only, no changes)';
    }

    public function setOutputCallback(\Closure $callback): void
    {
        $this->outputCallback = $callback;
    }

    public function execute(array $params = []): array
    {
        $this->output("Scanning sphinx_hotels.facilities_json for all facility IDs...");

        // Step 1: Extract all unique facility IDs and names from the database
        $hotels = db_get_array(
            "SELECT hotel_id, facilities_json FROM ?:sphinx_hotels WHERE facilities_json IS NOT NULL AND facilities_json != '[]' AND sync_status = 'active' LIMIT 5000"
        );

        $allFacilities = []; // id => name
        $facilityHotelCount = []; // id => count of hotels that have it
        foreach ($hotels as $hotel) {
            $facilities = json_decode($hotel['facilities_json'], true);
            if (!is_array($facilities)) continue;
            foreach ($facilities as $f) {
                $fid = (string) ($f['id'] ?? '');
                $fname = (string) ($f['name'] ?? '');
                if ($fid === '') continue;
                if (!isset($allFacilities[$fid])) {
                    $allFacilities[$fid] = $fname;
                    $facilityHotelCount[$fid] = 0;
                }
                $facilityHotelCount[$fid]++;
            }
        }

        $this->output("Found " . count($allFacilities) . " unique facility IDs across " . count($hotels) . " hotels.");

        // Step 2: Check which ones have aliases in travel_api_alias
        $mapped = [];
        $unmapped = [];
        $incomplete = [];

        foreach ($allFacilities as $fid => $fname) {
            $alias = db_get_row(
                "SELECT a.alias_id, a.map_id, m.cscart_feature_id, m.cscart_variant_id, m.canonical_code
                 FROM ?:travel_api_alias a
                 JOIN ?:travel_feature_map m ON m.map_id = a.map_id
                 WHERE a.api_source = 'sphinx' AND a.feature_type = 'facility' AND a.api_value = ?s",
                $fid
            );

            if (empty($alias)) {
                $unmapped[$fid] = $fname;
            } elseif (empty($alias['cscart_variant_id']) || (int)$alias['cscart_variant_id'] <= 0) {
                $incomplete[$fid] = ['name' => $fname, 'canonical' => $alias['canonical_code'] ?? '', 'feature_id' => $alias['cscart_feature_id'] ?? 0];
            } else {
                $mapped[$fid] = $fname;
            }
        }

        // Step 3: Report
        $this->output("");
        $this->output("=== FACILITY MAPPING AUDIT ===");
        $this->output("Mapped (fully working): " . count($mapped));
        $this->output("Incomplete (alias exists, no CS-Cart variant): " . count($incomplete));
        $this->output("Unmapped (no alias at all): " . count($unmapped));
        $this->output("");

        if (!empty($unmapped)) {
            arsort($facilityHotelCount);
            $this->output("--- UNMAPPED FACILITIES (sorted by hotel count) ---");
            // Sort unmapped by hotel count descending
            $sortedUnmapped = [];
            foreach ($unmapped as $fid => $fname) {
                $sortedUnmapped[$fid] = $facilityHotelCount[$fid] ?? 0;
            }
            arsort($sortedUnmapped);
            foreach ($sortedUnmapped as $fid => $count) {
                $this->output("  ID={$fid} \"{$unmapped[$fid]}\" ({$count} hotels)");
            }
            $this->output("");
        }

        if (!empty($incomplete)) {
            $this->output("--- INCOMPLETE MAPPINGS (need CS-Cart variant) ---");
            foreach ($incomplete as $fid => $info) {
                $this->output("  ID={$fid} \"{$info['name']}\" canonical={$info['canonical']} feature_id={$info['feature_id']}");
            }
            $this->output("");
        }

        return [
            'success' => true,
            'stats' => [
                'total_facilities' => count($allFacilities),
                'mapped' => count($mapped),
                'incomplete' => count($incomplete),
                'unmapped' => count($unmapped),
                'hotels_scanned' => count($hotels),
            ],
        ];
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}
