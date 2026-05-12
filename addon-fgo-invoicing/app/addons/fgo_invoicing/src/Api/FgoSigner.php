<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Api;

use Tygh\Addons\FgoInvoicing\Constants;

/**
 * Pure helpers for the FGO request-signing scheme.
 *
 * FGO does not use bearer tokens. Each POST body carries:
 *   - CodUnic    : merchant CUI / VAT id
 *   - Hash       : strtoupper(sha1(CodUnic . PrivateKey . context))
 *   - Token      : strtoupper(sha1(contextA . contextB . "4C490B5C"))
 *
 * Where the "context" varies by operation:
 *
 *   factura/check               Hash: ""                       Token: ""
 *   factura/emitere             Hash: customerName             Token: orderId + customerIdExtern
 *   factura/anulare/stornare/
 *     stergere/awb              Hash: invoiceNumber            Token: series + number
 *   articol/articolemodificate  Hash: ""                       Token: ""
 *
 * The customer name is fed through `convertDiacritics2()` first (Romanian
 * accents flattened to ASCII).
 *
 * The customer external id is fed through `normalizeCustomerId()` (CRC32
 * fallback for non-numeric ids; clamps to int32 to match the reference
 * PrestaShop and WooCommerce plugins).
 */
final class FgoSigner
{
    private function __construct()
    {
    }

    /** Hash for the credential preflight (POST /factura/check). */
    public static function checkHash(string $codUnic, string $privateKey): string
    {
        return strtoupper(sha1($codUnic . $privateKey));
    }

    /** Token for the credential preflight (POST /factura/check). */
    public static function checkToken(): string
    {
        return strtoupper(sha1(Constants::TOKEN_SALT));
    }

    /** Hash used when issuing a new invoice (POST /factura/emitere). */
    public static function issueHash(string $codUnic, string $privateKey, string $customerName): string
    {
        return strtoupper(sha1($codUnic . $privateKey . self::convertDiacritics2($customerName)));
    }

    /** Token used when issuing a new invoice. Customer id is normalised. */
    public static function issueToken(int|string $orderId, int|string $customerIdExtern): string
    {
        $idExtern = self::normalizeCustomerId($customerIdExtern);
        return strtoupper(sha1((string) $orderId . (string) $idExtern . Constants::TOKEN_SALT));
    }

    /** Hash for ops on an existing invoice (cancel / storno / delete / awb). */
    public static function existingInvoiceHash(string $codUnic, string $privateKey, string $invoiceNumber): string
    {
        return strtoupper(sha1($codUnic . $privateKey . $invoiceNumber));
    }

    /** Token for ops on an existing invoice. */
    public static function existingInvoiceToken(string $invoiceSeries, string $invoiceNumber): string
    {
        return strtoupper(sha1($invoiceSeries . $invoiceNumber . Constants::TOKEN_SALT));
    }

    /**
     * Romanian-diacritic-flattening transliteration matching the reference
     * plugins' CONFIG::convertDiacritics2().
     *
     * Mapping (per WooCommerce fgo-config.php:52):
     *   ă, â, Ă, Â  → a, A
     *   î, Î       → i, I
     *   ș, Ș       → s, S
     *   ț, ţ, Ț, Ţ → t, T
     */
    public static function convertDiacritics2(string $s): string
    {
        $out = $s;
        $out = str_replace(['ă', 'â'], 'a', $out);
        $out = str_replace(['Ă', 'Â'], 'A', $out);
        $out = str_replace('î', 'i', $out);
        $out = str_replace('Î', 'I', $out);
        $out = str_replace('ș', 's', $out);
        $out = str_replace('Ș', 'S', $out);
        $out = str_replace(['ț', 'ţ'], 't', $out);
        $out = str_replace(['Ț', 'Ţ'], 'T', $out);
        return $out;
    }

    /**
     * Mirror of WooCommerce CONFIG::normalize_customer_id_for_api():
     *   - numeric and ≤ INT32 → cast to int
     *   - non-numeric or > INT32 → CRC32 of the string, modulo INT32
     */
    public static function normalizeCustomerId(int|string $customerId): int
    {
        $max = 2147483647;
        $str = (string) $customerId;

        if (!is_numeric($str)) {
            return self::crcWithinInt32($str, $max);
        }

        $num = (float) $str;
        if ($num > (float) $max) {
            return self::crcWithinInt32($str, $max);
        }

        return (int) $num;
    }

    private static function crcWithinInt32(string $value, int $max): int
    {
        $crc = crc32($value);
        $unsigned = (int) sprintf('%u', $crc);
        return $unsigned > $max ? $unsigned % $max : $unsigned;
    }
}
