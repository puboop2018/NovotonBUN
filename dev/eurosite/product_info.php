<?php

declare(strict_types=1);

/**
 * Eurosite · getProductInfoRequest — hotel details (description, pictures,
 * coordinates) for one product.
 *
 * Usage (CLI):
 *   php product_info.php --country=RO --city=ROMM --product=RO0099 [--limit=5]
 * Usage (browser):
 *   product_info.php?country=RO&city=ROMM&product=RO0099&limit=5
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "product_info — Eurosite hotel/product details\n"
       . "  --country=XX  --city=CODE  --product=CODE (all required)\n"
       . "  --type=hotel  product type (default hotel)\n"
       . "  --limit=N     first N repeated rows only (pictures usually)\n";
    exit;
}

$cfg     = euro_config();
$country = strtoupper(euro_param('country', 'RO') ?? 'RO');
$city    = euro_param('city', '') ?? '';
$product = euro_param('product', '') ?? '';
$type    = euro_param('type', 'hotel') ?? 'hotel';
$limit   = (int) (euro_param('limit', '0') ?? '0');

if ($city === '' || $product === '') {
    euro_out_setup();
    echo "Missing --city= / --product= (find codes with hotel_search.php). --help for usage.\n";
    exit(1);
}

$details = '  <getProductInfoRequest>'
    . '<CountryCode>' . euro_esc($country) . '</CountryCode>'
    . '<CityCode>' . euro_esc($city) . '</CityCode>'
    . '<ProductCode>' . euro_esc($product) . '</ProductCode>'
    . '<ProductType>' . euro_esc($type) . '</ProductType>'
    . '</getProductInfoRequest>';

euro_run($cfg, 'getProductInfoRequest', $details, $limit);
