<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Exceptions;

/**
 * Thrown when input validation fails (booking data, search params, etc.).
 */
class ValidationException extends NovotonException
{
    private string $field;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, string $field = '', array $context = [], ?\Throwable $previous = null)
    {
        $this->field = $field;
        parent::__construct($message, array_merge($context, [
            'field' => $field,
        ]), 0, $previous);
    }

    public function getField(): string
    {
        return $this->field;
    }

    public static function missingRequired(string $field): self
    {
        return new self("Missing required field: {$field}", $field);
    }

    public static function invalidValue(string $field, string $reason): self
    {
        return new self("Invalid value for {$field}: {$reason}", $field);
    }
}
