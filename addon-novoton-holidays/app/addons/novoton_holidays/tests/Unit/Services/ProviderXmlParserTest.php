<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\ProviderXmlParser;

/**
 * Behavior tests for the provider XML parsing extracted from
 * functions/formatting.php — CDATA-tolerant loading and the
 * TermsOfPayment / TermsOfCancellation rule extraction.
 */
final class ProviderXmlParserTest extends TestCase
{
    public function testParseXmlStringHandlesRawCdataAndFragments(): void
    {
        self::assertNotNull(ProviderXmlParser::parseXmlString('<a><b>1</b></a>'));
        self::assertNotNull(ProviderXmlParser::parseXmlString('<![CDATA[<a><b>1</b></a>]]>'));
        // A tag-soup fragment gets a <root> wrapper instead of failing.
        self::assertNotNull(ProviderXmlParser::parseXmlString('<b>1</b><b>2</b>'));
        self::assertNull(ProviderXmlParser::parseXmlString(''));
    }

    public function testParsePaymentTermsReadsTheNovotonPercentFormat(): void
    {
        $xml = '<TermsOfPayment>'
            . '<Percent tillDate="2026-02-05">30</Percent>'
            . '<Percent tillDate="2026-08-21">70</Percent>'
            . '<Percent>0</Percent>'
            . '</TermsOfPayment>';

        $terms = ProviderXmlParser::parsePaymentTerms($xml, '%d.%m.%Y');

        self::assertCount(2, $terms, 'zero-percent rules are dropped');
        self::assertSame(30, $terms[0]['percent']);
        self::assertSame('2026-02-05', $terms[0]['date']);
        self::assertSame('05.02.2026', $terms[0]['date_formatted']);
        self::assertFalse($terms[0]['is_on_booking']);
        self::assertSame(70, $terms[1]['percent']);
    }

    public function testParsePaymentTermsMarksDatelessRulesAsOnBooking(): void
    {
        $terms = ProviderXmlParser::parsePaymentTerms('<TermsOfPayment><Percent>100</Percent></TermsOfPayment>');

        self::assertCount(1, $terms);
        self::assertTrue($terms[0]['is_on_booking']);
        self::assertSame('', $terms[0]['date_formatted']);
    }

    public function testParsePaymentTermsFallsBackToGenericPaymentRules(): void
    {
        $terms = ProviderXmlParser::parsePaymentTerms(
            '<rules><PaymentRule PerCent="40" DateTo="2026-03-01"/></rules>',
            '%d.%m.%Y',
        );

        self::assertCount(1, $terms);
        self::assertSame(40, $terms[0]['percent']);
        self::assertSame('01.03.2026', $terms[0]['date_formatted']);
    }

    public function testParseCancellationTermsMarksFreeRowsAndComputesDaysBefore(): void
    {
        $xml = '<TermsOfCancellation>'
            . '<Penalty tillDate="2026-09-02" Type="Percent">100</Penalty>'
            . '<Penalty tillDate="2026-08-25" Type="Over Nights">0</Penalty>'
            . '</TermsOfCancellation>';

        $terms = ProviderXmlParser::parseCancellationTerms($xml, '2026-09-04');

        self::assertCount(2, $terms);
        // Sorted by till_date ascending — the FREE row comes first.
        self::assertSame('FREE', $terms[0]['value']);
        self::assertFalse($terms[0]['is_penalty']);
        self::assertSame('2026-08-25', $terms[0]['till_date']);
        self::assertSame(10, $terms[0]['days_before']);

        self::assertSame(100.0, $terms[1]['value']);
        self::assertTrue($terms[1]['is_penalty']);
        self::assertSame(2, $terms[1]['days_before']);
    }

    public function testFreeCancellationDateReturnsTheFreeRowsDeadline(): void
    {
        $xml = '<TermsOfCancellation>'
            . '<Penalty tillDate="2026-08-25" Type="Percent">0</Penalty>'
            . '<Penalty tillDate="2026-09-02" Type="Percent">100</Penalty>'
            . '</TermsOfCancellation>';

        self::assertSame('2026-08-25', ProviderXmlParser::freeCancellationDate($xml));
        self::assertNull(ProviderXmlParser::freeCancellationDate(
            '<TermsOfCancellation><Penalty tillDate="2026-09-02">100</Penalty></TermsOfCancellation>',
        ));
        self::assertNull(ProviderXmlParser::freeCancellationDate(''));
    }

    public function testXmlToArrayMergesRepeatedChildrenAndKeepsAttributes(): void
    {
        $result = ProviderXmlParser::xmlToArray(
            '<hotel id="7"><room>DBL</room><room>SGL</room><name>Edart</name></hotel>',
        );

        self::assertSame(['DBL', 'SGL'], $result['room']);
        self::assertSame('Edart', $result['name']);
        self::assertSame('7', $result['@id']);
        self::assertSame([], ProviderXmlParser::xmlToArray('not xml at all <'));
    }
}
