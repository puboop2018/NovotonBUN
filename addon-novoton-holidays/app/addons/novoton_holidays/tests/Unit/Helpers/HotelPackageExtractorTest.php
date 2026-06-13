<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\HotelPackageExtractor;

/**
 * Characterization coverage for HotelPackageExtractor — the hotel-info XML
 * package parsing extracted from BatchedHotelInfoSyncV2. Pure SimpleXML in,
 * structured arrays out; the tests pin the dedupe-by-IdCont rule, the nested
 * <Package> handling, and the package-name resolution (direct, then XPath
 * fallback).
 */
#[CoversClass(HotelPackageExtractor::class)]
class HotelPackageExtractorTest extends TestCase
{
    private HotelPackageExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new HotelPackageExtractor();
    }

    private function xml(string $body): \SimpleXMLElement
    {
        $element = simplexml_load_string("<hotelinfo>{$body}</hotelinfo>");
        self::assertInstanceOf(\SimpleXMLElement::class, $element);
        return $element;
    }

    // ── extractPackages ──────────────────────────────────────────────────────

    public function testExtractsPackagesAcrossSiblings(): void
    {
        $packages = $this->extractor->extractPackages($this->xml(
            '<packages><IdCont>P1</IdCont><PackageName>Summer</PackageName></packages>'
            . '<packages><IdCont>P2</IdCont><PackageName>Winter</PackageName></packages>',
        ));

        $this->assertSame([
            ['IdCont' => 'P1', 'PackageName' => 'Summer'],
            ['IdCont' => 'P2', 'PackageName' => 'Winter'],
        ], $packages);
    }

    public function testExtractPackagesDedupesByIdCont(): void
    {
        $packages = $this->extractor->extractPackages($this->xml(
            '<packages><IdCont>P1</IdCont><PackageName>First</PackageName></packages>'
            . '<packages><IdCont>P1</IdCont><PackageName>Duplicate</PackageName></packages>',
        ));

        $this->assertCount(1, $packages);
        $this->assertSame('First', $packages[0]['PackageName']);
    }

    public function testExtractPackagesSkipsEmptyIdCont(): void
    {
        $packages = $this->extractor->extractPackages($this->xml(
            '<packages><PackageName>No ID</PackageName></packages>'
            . '<packages><IdCont>P9</IdCont><PackageName>Has ID</PackageName></packages>',
        ));

        $this->assertSame([['IdCont' => 'P9', 'PackageName' => 'Has ID']], $packages);
    }

    public function testExtractPackagesHandlesNestedPackageElements(): void
    {
        $packages = $this->extractor->extractPackages($this->xml(
            '<packages><IdCont>P1</IdCont><PackageName>Outer</PackageName>'
            . '<Package><IdCont>N1</IdCont><PackageName>Nested</PackageName></Package>'
            . '</packages>',
        ));

        $this->assertSame([
            ['IdCont' => 'P1', 'PackageName' => 'Outer'],
            ['IdCont' => 'N1', 'PackageName' => 'Nested'],
        ], $packages);
    }

    public function testExtractPackagesEmptyWhenNoPackagesElement(): void
    {
        $this->assertSame([], $this->extractor->extractPackages($this->xml('<name>Hotel</name>')));
    }

    public function testCountPackages(): void
    {
        $count = $this->extractor->countPackages($this->xml(
            '<packages><IdCont>P1</IdCont></packages>'
            . '<packages><IdCont>P2</IdCont></packages>'
            . '<packages><IdCont>P2</IdCont></packages>', // duplicate, not counted
        ));

        $this->assertSame(2, $count);
    }

    // ── extractPackageName ───────────────────────────────────────────────────

    public function testExtractPackageNameFromDirectPackageName(): void
    {
        $name = $this->extractor->extractPackageName($this->xml(
            '<packages><PackageName>Summer Deal</PackageName></packages>',
        ));

        $this->assertSame('Summer Deal', $name);
    }

    public function testExtractPackageNameFallsBackToPackageElement(): void
    {
        $name = $this->extractor->extractPackageName($this->xml(
            '<packages><Package>Winter Deal</Package></packages>',
        ));

        $this->assertSame('Winter Deal', $name);
    }

    public function testExtractPackageNameFallsBackToXpath(): void
    {
        // No <packages>, but a <PackageName> exists deeper in the document.
        $name = $this->extractor->extractPackageName($this->xml(
            '<offers><offer><PackageName>Deep Deal</PackageName></offer></offers>',
        ));

        $this->assertSame('Deep Deal', $name);
    }

    public function testExtractPackageNameEmptyWhenAbsent(): void
    {
        $this->assertSame('', $this->extractor->extractPackageName($this->xml('<name>Hotel</name>')));
    }
}
