<?php
declare(strict_types=1);
/**
 * Novoton XML Parser
 * Handles XML cleaning (entity sanitization) and parsing.
 *
 * Path: app/addons/novoton_holidays/src/NovotonXmlParser.php
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;

class NovotonXmlParser implements XmlParserInterface
{
    /**
     * Clean XML entities
     * Only encode bare ampersands that would break XML parsing.
     * Skips CDATA sections where bare ampersands are valid literal content.
     *
     * @param string|null|false $string Raw XML/HTML string
     * @return string Cleaned string
     */
    public function clean($string): string
    {
        if (empty($string)) {
            return (string)$string;
        }

        // Fast path: no ampersands at all
        if (strpos($string, '&') === false) {
            return $string;
        }

        // Split around CDATA sections so we only escape bare & in non-CDATA parts.
        // Inside CDATA, & is a valid literal character and must NOT be escaped.
        $parts = preg_split('/(<!\[CDATA\[.*?\]\]>)/s', $string, -1, PREG_SPLIT_DELIM_CAPTURE);

        $result = '';
        foreach ($parts as $part) {
            if (strpos($part, '<![CDATA[') === 0) {
                // CDATA section — keep as-is
                $result .= $part;
            } else {
                // Regular XML text — escape bare & that are not valid entity refs
                $result .= preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;|#)/', '&amp;', $part);
            }
        }

        return $result;
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

        $prevLibxml = libxml_use_internal_errors(true);

        $options = LIBXML_NOCDATA | LIBXML_NONET;
        if ($size > 100000) {
            $options |= LIBXML_COMPACT | LIBXML_NOBLANKS;
        }

        try {
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
                throw new XmlParsingException(
                    'XML Parse Error: ' . implode('; ', array_slice($error_messages, 0, 5)),
                    $error_messages,
                    $size
                );
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevLibxml);
        }
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
        $prevLibxml = libxml_use_internal_errors(true);

        $options = LIBXML_NOCDATA | LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOBLANKS | LIBXML_PARSEHUGE;

        try {
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
                throw new XmlParsingException(
                    'XML Parse Error (streaming): ' . implode('; ', array_slice($error_messages, 0, 5)),
                    $error_messages,
                    $size
                );
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevLibxml);
        }
    }
}
