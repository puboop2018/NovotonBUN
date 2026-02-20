<?php
declare(strict_types=1);
/**
 * Novoton XML Parser
 * Handles XML cleaning (entity sanitization) and parsing.
 *
 * Path: app/addons/novoton_holidays/src/NovotonXmlParser.php
 */

namespace Tygh\Addons\NovotonHolidays;

require_once __DIR__ . '/Exceptions/NovotonException.php';
require_once __DIR__ . '/Exceptions/XmlParsingException.php';

use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;

class NovotonXmlParser
{
    /**
     * Clean XML entities
     * Only encode bare ampersands that would break XML parsing.
     * Does NOT remove ampersands inside CDATA sections (they're valid there).
     *
     * @param string|null|false $string Raw XML/HTML string
     * @return string Cleaned string
     */
    public function clean($string): string
    {
        if (empty($string)) {
            return (string)$string;
        }

        // For very large responses (>500KB), be more conservative to avoid memory issues
        $is_large = strlen($string) > 500000;

        // Only replace bare & that are not part of valid entities
        if ($is_large) {
            if (strpos($string, '&') !== false && strpos($string, '&amp;') === false) {
                $string = preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;|#)/', '&amp;', $string, 1000);
            }
        } else {
            $string = preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;|#)/', '&amp;', $string);
        }

        return $string;
    }

    /**
     * Parse XML string into SimpleXMLElement
     * Uses streaming mode for responses > 1MB.
     *
     * @param string|null|false $xmlString Cleaned XML string
     * @return \SimpleXMLElement Parsed XML
     * @throws XmlParsingException On empty response or parse failure
     */
    public function parse($xmlString): \SimpleXMLElement
    {
        if (empty($xmlString)) {
            fn_log_event('general', 'runtime', [
                'message' => 'XML Parse Error - Empty response',
                'raw_response' => '(empty)'
            ]);
            throw new XmlParsingException('XML Parse Error - Empty response', [], 0);
        }

        $size = strlen($xmlString);

        // For very large responses (>1MB), use optimized streaming flags
        if ($size > 1000000) {
            return $this->parseStreaming($xmlString);
        }

        libxml_use_internal_errors(true);

        $options = LIBXML_NOCDATA | LIBXML_NONET;
        if ($size > 100000) {
            $options |= LIBXML_COMPACT | LIBXML_NOBLANKS;
        }

        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', $options);

        if ($xml === false) {
            $errors = libxml_get_errors();
            $error_messages = [];
            foreach ($errors as $err) {
                $error_messages[] = "Line {$err->line}: {$err->message}";
            }
            fn_log_event('general', 'runtime', [
                'message' => 'XML Parse Error',
                'errors' => implode('; ', array_slice($error_messages, 0, 5)),
                'response_size' => $size,
                'raw_response_first_500' => substr($xmlString, 0, 500)
            ]);
            libxml_clear_errors();
            throw new XmlParsingException(
                'XML Parse Error: ' . implode('; ', array_slice($error_messages, 0, 5)),
                $error_messages,
                $size
            );
        }

        return $xml;
    }

    /**
     * Convenience: clean then parse
     *
     * @param string|null|false $rawString Raw XML string
     * @return \SimpleXMLElement Parsed XML
     * @throws XmlParsingException On empty input or parse failure
     */
    public function cleanAndParse($rawString): \SimpleXMLElement
    {
        if (empty($rawString)) {
            throw new XmlParsingException('XML Parse Error - Empty response', [], 0);
        }
        return $this->parse($this->clean($rawString));
    }

    /**
     * Streaming XML parser for very large responses (>1MB).
     * Uses maximum optimization flags to minimize memory usage.
     *
     * @param string $xmlString Raw XML string
     * @return \SimpleXMLElement Parsed XML
     * @throws XmlParsingException On parse failure
     */
    private function parseStreaming(string $xmlString): \SimpleXMLElement
    {
        libxml_use_internal_errors(true);

        $options = LIBXML_NOCDATA | LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOBLANKS | LIBXML_PARSEHUGE;

        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', $options);

        if ($xml === false) {
            $errors = libxml_get_errors();
            $error_messages = [];
            foreach ($errors as $err) {
                $error_messages[] = "Line {$err->line}: {$err->message}";
            }
            $size = strlen($xmlString);
            fn_log_event('general', 'runtime', [
                'message' => 'XML Parse Error (streaming mode)',
                'errors' => implode('; ', array_slice($error_messages, 0, 5)),
                'response_size' => $size,
                'raw_response_first_500' => substr($xmlString, 0, 500)
            ]);
            libxml_clear_errors();
            throw new XmlParsingException(
                'XML Parse Error (streaming): ' . implode('; ', array_slice($error_messages, 0, 5)),
                $error_messages,
                $size
            );
        }

        return $xml;
    }
}
