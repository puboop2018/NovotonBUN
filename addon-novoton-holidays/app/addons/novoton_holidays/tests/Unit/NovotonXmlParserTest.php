<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;

#[CoversClass(NovotonXmlParser::class)]
class NovotonXmlParserTest extends TestCase
{
    private NovotonXmlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new NovotonXmlParser();
    }

    // ── clean() ──────────────────────────────────────────────────────────

    public function testCleanEmptyString(): void
    {
        $this->assertSame('', $this->parser->clean(''));
    }

    public function testCleanNullReturnsEmptyString(): void
    {
        $this->assertSame('', $this->parser->clean(null));
    }

    public function testCleanFalseReturnsEmptyString(): void
    {
        $this->assertSame('', $this->parser->clean(false));
    }

    public function testCleanNoAmpersands(): void
    {
        $xml = '<hotel><name>Grand Hotel</name></hotel>';
        $this->assertSame($xml, $this->parser->clean($xml));
    }

    public function testCleanEscapesBareAmpersands(): void
    {
        $xml = '<hotel><name>Sun & Sea Hotel</name></hotel>';
        $expected = '<hotel><name>Sun &amp; Sea Hotel</name></hotel>';
        $this->assertSame($expected, $this->parser->clean($xml));
    }

    public function testCleanPreservesExistingEntities(): void
    {
        $xml = '<hotel><name>Sun &amp; Sea &lt;Hotel&gt;</name></hotel>';
        $this->assertSame($xml, $this->parser->clean($xml));
    }

    public function testCleanPreservesCdataSections(): void
    {
        $xml = '<hotel><desc><![CDATA[Sun & Sea & Fun]]></desc></hotel>';
        $this->assertSame($xml, $this->parser->clean($xml));
    }

    public function testCleanMixedCdataAndText(): void
    {
        $xml = '<root><a>Tom & Jerry</a><b><![CDATA[Rock & Roll]]></b></root>';
        $expected = '<root><a>Tom &amp; Jerry</a><b><![CDATA[Rock & Roll]]></b></root>';
        $this->assertSame($expected, $this->parser->clean($xml));
    }

    // ── parse() ──────────────────────────────────────────────────────────

    public function testParseValidXml(): void
    {
        $xml = '<hotel><name>Test</name><id>123</id></hotel>';
        $result = $this->parser->parse($xml);

        $this->assertInstanceOf(\SimpleXMLElement::class, $result);
        $this->assertSame('Test', (string) $result->name);
        $this->assertSame('123', (string) $result->id);
    }

    public function testParseThrowsOnEmptyString(): void
    {
        $this->expectException(XmlParsingException::class);
        $this->parser->parse('');
    }

    public function testParseThrowsOnNull(): void
    {
        $this->expectException(XmlParsingException::class);
        $this->parser->parse(null);
    }

    public function testParseThrowsOnInvalidXml(): void
    {
        $this->expectException(XmlParsingException::class);
        $this->parser->parse('<hotel><name>unclosed');
    }

    public function testParseHandlesXmlDeclaration(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><root><item>ok</item></root>';
        $result = $this->parser->parse($xml);
        $this->assertSame('ok', (string) $result->item);
    }

    // ── cleanAndParse() ──────────────────────────────────────────────────

    public function testCleanAndParseWithBareAmpersand(): void
    {
        $xml = '<hotel><name>Sun & Beach</name></hotel>';
        $result = $this->parser->cleanAndParse($xml);
        $this->assertSame('Sun & Beach', (string) $result->name);
    }

    public function testCleanAndParseThrowsOnEmpty(): void
    {
        $this->expectException(XmlParsingException::class);
        $this->parser->cleanAndParse('');
    }

    public function testParseWithAttributes(): void
    {
        $xml = '<hotels count="3"><hotel id="H1">One</hotel></hotels>';
        $result = $this->parser->parse($xml);
        $this->assertSame('3', (string) $result['count']);
        $this->assertSame('H1', (string) $result->hotel['id']);
    }

    public function testParseWithNamespaces(): void
    {
        $xml = '<root xmlns:h="http://test.com"><h:hotel>Test</h:hotel></root>';
        $result = $this->parser->parse($xml);
        $this->assertInstanceOf(\SimpleXMLElement::class, $result);
    }
}
