<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Api;

use RuntimeException;
use Throwable;

/**
 * Thrown when FGO replies with Success=false, when the response cannot be
 * decoded, or when the HTTP layer surfaces a non-recoverable failure.
 *
 * Carries the raw decoded payload (when available) so callers can persist
 * it for diagnostics.
 */
final class FgoApiException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $rawResponse
     */
    public function __construct(
        string $message,
        public readonly ?array $rawResponse = null,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
