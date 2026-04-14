<?php

declare(strict_types=1);

/**
 * XML Parser Interface
 *
 * Contract for XML cleaning and parsing operations.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays;

interface XmlParserInterface
{
    /**
     * Clean XML entities (encode bare ampersands that would break XML parsing).
     *
     * @param string|null|false $string Raw XML/HTML string
     * @return string Cleaned string
     */
    public function clean($string): string;

    /**
     * Parse XML string into SimpleXMLElement.
     *
     * @param string|null|false $xmlString Cleaned XML string
     * @return \SimpleXMLElement Parsed XML
     * @throws \Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException
     */
    public function parse($xmlString): \SimpleXMLElement;

    /**
     * Convenience: clean then parse.
     *
     * @param string|null|false $rawString Raw XML string
     * @return \SimpleXMLElement Parsed XML
     * @throws \Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException
     */
    public function cleanAndParse($rawString): \SimpleXMLElement;
}
