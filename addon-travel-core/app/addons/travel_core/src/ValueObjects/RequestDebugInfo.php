<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ValueObjects;

/**
 * Immutable value object encapsulating API request/response debug state.
 *
 * Shared across all travel provider addons (novoton, sphinx, etc.)
 * to provide consistent debug introspection for API calls.
 */
class RequestDebugInfo
{
    public readonly string $lastRequest;
    public readonly string $lastResponse;
    public readonly string $lastResponseRaw;
    public readonly array $lastRequestFormatted;
    public readonly string $lastError;
    public readonly int $lastHttpCode;

    public function __construct(
        string $lastRequest = '',
        string $lastResponse = '',
        string $lastResponseRaw = '',
        array $lastRequestFormatted = [],
        string $lastError = '',
        int $lastHttpCode = 0
    ) {
        $this->lastRequest = $lastRequest;
        $this->lastResponse = $lastResponse;
        $this->lastResponseRaw = $lastResponseRaw;
        $this->lastRequestFormatted = $lastRequestFormatted;
        $this->lastError = $lastError;
        $this->lastHttpCode = $lastHttpCode;
    }

    /**
     * Create from an API client instance with public debug properties.
     */
    public static function fromClient(object $client): self
    {
        return new self(
            $client->lastRequest ?? '',
            $client->lastResponse ?? '',
            $client->lastResponseRaw ?? '',
            $client->lastRequestFormatted ?? [],
            $client->lastError ?? '',
            $client->lastHttpCode ?? 0
        );
    }

    public function hasError(): bool
    {
        return $this->lastError !== '' || ($this->lastHttpCode !== 0 && $this->lastHttpCode !== 200);
    }

    public function getErrorSummary(): string
    {
        $error = $this->lastError;
        if ($this->lastHttpCode && $this->lastHttpCode !== 200) {
            $error .= " (HTTP {$this->lastHttpCode})";
        }
        return trim($error);
    }
}
