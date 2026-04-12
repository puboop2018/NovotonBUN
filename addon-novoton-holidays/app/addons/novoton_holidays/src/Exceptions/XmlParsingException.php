<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Exceptions;

/**
 * Thrown when an XML response cannot be parsed.
 */
class XmlParsingException extends NovotonException
{
    private $xmlErrors;
    private $responseSize;

    /**
     * @param array<string, mixed> $xmlErrors
     */
    public function __construct(string $message, array $xmlErrors = [], int $responseSize = 0, ?\Throwable $previous = null)
    {
        $this->xmlErrors = $xmlErrors;
        $this->responseSize = $responseSize;
        parent::__construct($message, [
            'xml_errors' => $xmlErrors,
            'response_size' => $responseSize,
        ], 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function getXmlErrors(): array
    {
        return $this->xmlErrors;
    }

    public function getResponseSize(): int
    {
        return $this->responseSize;
    }
}
