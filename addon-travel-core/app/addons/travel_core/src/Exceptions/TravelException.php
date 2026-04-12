<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Exceptions;

class TravelException extends \RuntimeException
{
    protected array $context = [];

    public function __construct(string $message = '', array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }
}
