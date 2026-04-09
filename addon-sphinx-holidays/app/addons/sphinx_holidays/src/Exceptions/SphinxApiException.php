<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Exceptions;

/**
 * Thrown when a Sphinx API call fails (HTTP errors, timeouts, unexpected responses).
 */
class SphinxApiException extends SphinxException
{
    private int $httpCode;

    public function __construct(string $message = '', int $httpCode = 0, int $code = 0, ?\Throwable $previous = null)
    {
        $this->httpCode = $httpCode;
        parent::__construct($message, ['http_code' => $httpCode], $code, $previous);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
