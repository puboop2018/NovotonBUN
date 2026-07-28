<?php

/**
 * sphinx-relink.php — sandbox report + repair for sphinx hotel/product links.
 *
 * WHY: a sphinx hotel product page (booking form, location line) resolves
 * through ?:sphinx_hotels.product_id. When a product exists in CS-Cart but no
 * hotel row points at it — fresh addon reinstall, hotels table re-synced, a
 * hotel that arrived after the last sync — the page renders as a plain catalog
 * item. The repair is HotelSyncService::relinkExistingProducts(): fetch each
 * such hotel from the Sphinx API by the id inside its product code and
 * re-insert it with the link.
 *
 * The admin action (sphinx_holidays.relink_products) is POST-only, so it can
 * only be triggered from its button on the Sphinx dashboard. This page is the
 * clickable equivalent for the sandbox, plus the per-product report the admin
 * screen does not show.
 *
 * WHERE: the fullstore sandbox only — docker/fullstore/docker-compose.yml
 * bind-mounts dev/ at /var/www/html/dev. Refuses non-localhost HTTP requests.
 *
 * USE:
 *   http://localhost:8080/dev/tools/sphinx-relink.php          → report only
 *   http://localhost:8080/dev/tools/sphinx-relink.php?force=1  → run the relink
 *   CLI: docker compose exec app php /var/www/html/dev/tools/sphinx-relink.php [force]
 */

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\HotelSyncService;

$sr_is_cli = PHP_SAPI === 'cli';

if (!$sr_is_cli) {
    $sr_host = strtolower((string) strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'));
    if (!in_array($sr_host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        http_response_code(403);
        exit("sphinx-relink is a local sandbox tool - refusing non-localhost request\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$sr_docroot = dirname(__DIR__, 2);
if (!is_file($sr_docroot . '/init.php')) {
    exit("No CS-Cart init.php at {$sr_docroot} — this tool only works from the fullstore\n"
        . "container, where dev/ is mounted inside the CS-Cart docroot (see docker/fullstore).\n");
}

define('AREA', 'A');
define('ACCOUNT_TYPE', 'admin');
require $sr_docroot . '/init.php';

$sr_force = $sr_is_cli
    ? in_array('force', array_slice((array) ($GLOBALS['argv'] ?? []), 1), true)
    : !empty($_GET['force']);

function sr_line(string $s = ''): void
{
    echo $s, "\n";
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

sr_line('sphinx-relink — hotel/product link report (' . date('Y-m-d H:i:s') . ')');
sr_line();

if (!class_exists(Container::class)) {
    exit("sphinx_holidays is not installed/active in this store.\n");
}

$sr_prefix = ConfigProvider::getProductCodePrefix();

/**
 * Sphinx-shaped products: the legacy configured prefix + the country-prefixed
 * shape the product factory writes (TR3612). Two letters, so novoton's
 * NVT-prefixed codes never match.
 *
 * @var array<int, array<string, mixed>> $sr_products
 */
$sr_products = (array) db_get_array(
    "SELECT p.product_id, p.product_code, d.product AS name,
            (SELECT h.hotel_id FROM ?:sphinx_hotels h WHERE h.product_id = p.product_id LIMIT 1) AS linked_hotel
     FROM ?:products p
     LEFT JOIN ?:product_descriptions d
            ON d.product_id = p.product_id AND d.lang_code = ?s
     WHERE p.product_code LIKE ?l OR p.product_code REGEXP ?s
     ORDER BY p.product_code",
    defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en',
    $sr_prefix . '%',
    '^[A-Z]{2}[0-9]+$'
);

$sr_unlinked = [];
foreach ($sr_products as $sr_row) {
    if ((string) ($sr_row['linked_hotel'] ?? '') === '') {
        $sr_unlinked[] = $sr_row;
    }
}

sr_line('sphinx-shaped products: ' . count($sr_products)
    . '   linked: ' . (count($sr_products) - count($sr_unlinked))
    . '   UNLINKED: ' . count($sr_unlinked));
sr_line('(unlinked = no ?:sphinx_hotels row points at the product → no booking form on its page)');
sr_line();

if ($sr_unlinked !== []) {
    sr_line('== unlinked products ==');
    foreach ($sr_unlinked as $sr_row) {
        $sr_code = (string) ($sr_row['product_code'] ?? '');
        $sr_hotelId = str_starts_with($sr_code, $sr_prefix)
            ? substr($sr_code, strlen($sr_prefix))
            : (string) preg_replace('/^[A-Z]{2}/', '', $sr_code);
        // A row may exist but point elsewhere (or nowhere) — say which.
        $sr_rowState = db_get_field(
            'SELECT COALESCE(product_id, 0) FROM ?:sphinx_hotels WHERE hotel_id = ?s LIMIT 1',
            $sr_hotelId
        );
        $sr_state = $sr_rowState === null
            ? 'no hotel row (relink fetches it from the API)'
            : ((int) $sr_rowState === 0
                ? 'hotel row exists, unlinked (heals on first product-page view too)'
                : 'hotel row linked to product ' . (int) $sr_rowState . ' (CONFLICT — inspect manually)');
        sr_line('  ' . str_pad($sr_code, 12) . ' product ' . str_pad((string) ($sr_row['product_id'] ?? ''), 6)
            . ' hotel ' . str_pad($sr_hotelId, 10) . $sr_state);
        sr_line('  ' . str_pad('', 12) . (string) ($sr_row['name'] ?? ''));
    }
    sr_line();
}

if (!$sr_force) {
    sr_line($sr_unlinked === []
        ? 'VERDICT: every sphinx-shaped product is linked. If a hotel page still lacks the'
            . "\nbooking form, clear var/cache and hard-refresh (Ctrl+F5)."
        : 'Re-run with ?force=1 (or CLI arg "force") to fetch the missing hotels from the'
            . "\nSphinx API and link them. Same action as the dashboard's Relink button.");
    exit;
}

if (!ConfigProvider::isConfigured()) {
    exit("Sphinx API is not configured (credentials missing) — cannot fetch hotels.\n");
}

sr_line('== running relink ==');
if (function_exists('set_time_limit')) {
    set_time_limit(0);
}

$sr_service = new HotelSyncService(
    Container::getApi(),
    Container::getHotelRepository(),
    Container::getDestinationRepository(),
    Container::getHotelSkipRepository()
);

$sr_stats = $sr_service->relinkExistingProducts(static function (int $i, int $total, string $hotelId): void {
    sr_line('  [' . $i . '/' . $total . '] hotel ' . $hotelId);
});

sr_line();
sr_line('linked:    ' . (int) ($sr_stats['linked'] ?? 0));
sr_line('skipped:   ' . (int) ($sr_stats['skipped'] ?? 0) . '  (already linked)');
sr_line('not found: ' . (int) ($sr_stats['not_found'] ?? 0) . '  (provider no longer offers the hotel)');
sr_line('errors:    ' . (int) ($sr_stats['errors'] ?? 0));
sr_line('total:     ' . (int) ($sr_stats['total'] ?? 0));
sr_line();

if (function_exists('fn_clear_cache')) {
    fn_clear_cache();
    sr_line('cleared CS-Cart cache (var/cache)');
}

sr_line((int) ($sr_stats['linked'] ?? 0) > 0
    ? 'VERDICT: links written. Hard-refresh the hotel page (Ctrl+F5) — the booking form should appear.'
    : 'VERDICT: nothing linked. Check the counts above: "not found" means the Sphinx API does not'
        . "\nreturn that hotel id any more (the product is stale); \"errors\" means API/transport trouble.");
