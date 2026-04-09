<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Exceptions;

/**
 * Thrown when hotel/package sync operations fail.
 */
class SyncException extends NovotonException
{
    private string $syncType;
    private string $entityId;

    public function __construct(string $message, string $syncType = '', string $entityId = '', array $context = [], ?\Throwable $previous = null)
    {
        $this->syncType = $syncType;
        $this->entityId = $entityId;
        parent::__construct($message, array_merge($context, [
            'sync_type' => $syncType,
            'entity_id' => $entityId,
        ]), 0, $previous);
    }

    public function getSyncType(): string
    {
        return $this->syncType;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public static function hotelSyncFailed(string $hotelId, string $error, ?\Throwable $previous = null): self
    {
        return new self("Hotel sync failed for {$hotelId}: {$error}", 'hotel_info', $hotelId, [], $previous);
    }

    public static function packageSyncFailed(string $hotelId, string $packageId, string $error, ?\Throwable $previous = null): self
    {
        return new self("Package sync failed for {$hotelId}/{$packageId}: {$error}", 'price_info', "{$hotelId}/{$packageId}", [], $previous);
    }
}
