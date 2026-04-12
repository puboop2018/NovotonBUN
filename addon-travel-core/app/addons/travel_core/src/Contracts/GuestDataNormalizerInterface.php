<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Guest Data Normalizer Interface
 *
 * Contract for standardizing guest data into canonical keyed format.
 * Used by all travel providers (Novoton, Sphinx, etc.) to normalize
 * guest form submissions into a consistent internal format.
 */
interface GuestDataNormalizerInterface
{
    /**
     * Normalize guest data from any supported format into canonical keyed format.
     *
     * @param array<string, mixed>|string $raw  Raw guest data (JSON string, keyed array, or indexed array)
     * @return array<string, mixed> Canonical keyed array (e.g. ['room1_adult_1' => [...], ...])
     */
    public function normalize(array|string $raw): array;

    /**
     * Decode a JSON string or pass through an array unchanged.
     *
     * @param array<string, mixed>|string $raw
     * @return array<string, mixed>
     */
    public function decode(array|string $raw): array;

    /**
     * Encode canonical guest data to JSON for database storage.
     *
     * Normalizes before encoding to guarantee canonical format in DB.
     *
     * @param array<string, mixed>|string $data Guest data in any format
     * @return string JSON string in canonical keyed format
     */
    public function toJson(array|string $data): string;

    /**
     * Detect whether data is already in the canonical keyed format.
     *
     * Keyed format uses string keys matching "room{N}_{type}_{I}".
     *
     * @param array<int|string, mixed> $data
     * @return bool
     */
    public function isKeyedFormat(array $data): bool;

    /**
     * Detect whether data is in the legacy indexed-array format.
     *
     * Array format uses sequential numeric keys with guest entries.
     *
     * @param array<int|string, mixed> $data
     * @return bool
     */
    public function isArrayFormat(array $data): bool;
}
