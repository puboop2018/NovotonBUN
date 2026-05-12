<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Api\FgoSigner;
use Tygh\Addons\FgoInvoicing\Constants;

/**
 * Validates that FgoSigner produces the same hash and token bytes that the
 * official PrestaShop and WooCommerce FGO plugins produce, against PHP's
 * native sha1 of the documented input.
 */
#[CoversClass(FgoSigner::class)]
final class FgoSignerTest extends TestCase
{
    public function testCheckHashMatchesNativeSha1(): void
    {
        $expected = strtoupper(sha1('CODUNIC' . 'PRIVATEKEY'));
        self::assertSame($expected, FgoSigner::checkHash('CODUNIC', 'PRIVATEKEY'));
    }

    public function testCheckTokenMatchesNativeSha1OfSalt(): void
    {
        $expected = strtoupper(sha1(Constants::TOKEN_SALT));
        self::assertSame($expected, FgoSigner::checkToken());
    }

    public function testIssueHashAsciiInput(): void
    {
        $expected = strtoupper(sha1('12345' . 'secret' . 'Ion Popescu'));
        self::assertSame($expected, FgoSigner::issueHash('12345', 'secret', 'Ion Popescu'));
    }

    public function testIssueHashStripsRomanianDiacriticsBeforeHashing(): void
    {
        // The reference plugins flatten "ăâîșț" → "aaist" before signing.
        $expected = strtoupper(sha1('12345' . 'secret' . 'Stefan Tandarei'));
        self::assertSame($expected, FgoSigner::issueHash('12345', 'secret', 'Ștefan Țăndărei'));
    }

    public function testIssueTokenWithNumericCustomerId(): void
    {
        $expected = strtoupper(sha1('1001' . '42' . Constants::TOKEN_SALT));
        self::assertSame($expected, FgoSigner::issueToken(1001, 42));
    }

    public function testIssueTokenWithNumericStringCustomerId(): void
    {
        $expected = strtoupper(sha1('1001' . '42' . Constants::TOKEN_SALT));
        self::assertSame($expected, FgoSigner::issueToken('1001', '42'));
    }

    public function testIssueTokenWithNonNumericCustomerIdFallsBackToCrc32(): void
    {
        $crc = (int) sprintf('%u', crc32('user-abc'));
        $normalized = $crc > 2147483647 ? $crc % 2147483647 : $crc;

        $expected = strtoupper(sha1('1001' . (string) $normalized . Constants::TOKEN_SALT));
        self::assertSame($expected, FgoSigner::issueToken(1001, 'user-abc'));
    }

    public function testIssueTokenWithCustomerIdOverflowingInt32(): void
    {
        $oversize = '99999999999999';
        $crc = (int) sprintf('%u', crc32($oversize));
        $normalized = $crc > 2147483647 ? $crc % 2147483647 : $crc;

        $expected = strtoupper(sha1('1001' . (string) $normalized . Constants::TOKEN_SALT));
        self::assertSame($expected, FgoSigner::issueToken('1001', $oversize));
    }

    public function testExistingInvoiceHash(): void
    {
        $expected = strtoupper(sha1('CODUNIC' . 'PRIVATEKEY' . '12345'));
        self::assertSame($expected, FgoSigner::existingInvoiceHash('CODUNIC', 'PRIVATEKEY', '12345'));
    }

    public function testExistingInvoiceToken(): void
    {
        $expected = strtoupper(sha1('F' . '12345' . Constants::TOKEN_SALT));
        self::assertSame($expected, FgoSigner::existingInvoiceToken('F', '12345'));
    }

    public function testConvertDiacritics2HandlesAllRomanianLettersBothCases(): void
    {
        // Input order: ă â î Ă Â Î ș Ș ț Ț ţ Ţ
        // Expected:    a a i A A I s S t T t T
        self::assertSame('aaiAAIsStTtT', FgoSigner::convertDiacritics2('ăâîĂÂÎșȘțȚţŢ'));
    }

    public function testConvertDiacritics2PreservesAsciiAndPunctuation(): void
    {
        self::assertSame('SC ABC SRL, str. Mare 1', FgoSigner::convertDiacritics2('SC ABC SRL, str. Mare 1'));
    }

    public function testNormalizeCustomerIdReturnsIntForNumericInRange(): void
    {
        self::assertSame(42, FgoSigner::normalizeCustomerId(42));
        self::assertSame(42, FgoSigner::normalizeCustomerId('42'));
        self::assertSame(0, FgoSigner::normalizeCustomerId(0));
    }

    public function testNormalizeCustomerIdWrapsNonNumericViaCrc32(): void
    {
        $unsigned = (int) sprintf('%u', crc32('foo'));
        $expected = $unsigned > 2147483647 ? $unsigned % 2147483647 : $unsigned;
        self::assertSame($expected, FgoSigner::normalizeCustomerId('foo'));
    }

    public function testHashesAreUppercaseHex(): void
    {
        self::assertMatchesRegularExpression('/^[0-9A-F]{40}$/', FgoSigner::checkHash('A', 'B'));
        self::assertMatchesRegularExpression('/^[0-9A-F]{40}$/', FgoSigner::checkToken());
        self::assertMatchesRegularExpression('/^[0-9A-F]{40}$/', FgoSigner::issueHash('A', 'B', 'X'));
        self::assertMatchesRegularExpression('/^[0-9A-F]{40}$/', FgoSigner::issueToken(1, 1));
        self::assertMatchesRegularExpression('/^[0-9A-F]{40}$/', FgoSigner::existingInvoiceHash('A', 'B', '1'));
        self::assertMatchesRegularExpression('/^[0-9A-F]{40}$/', FgoSigner::existingInvoiceToken('F', '1'));
    }
}
