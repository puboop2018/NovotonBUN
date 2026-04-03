<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Exceptions;

class NovotonException extends \RuntimeException
{
    protected array $context = [];

    public function __construct(string $message = '', array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
