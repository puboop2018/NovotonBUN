<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Search;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Destination part of a search query — any combination of structured
 * fields (country / region / city) and free-text "destination" input.
 *
 * All fields empty ⇒ `isEmpty()` returns true (homepage / any-hotel search).
 */
final readonly class Destination
{
    public function __construct(
        public string $country,
        public string $region,
        public string $city,
        public string $freeText,
    ) {
    }

    /**
     * @param array<string, mixed> $raw Request bag (typically `$_REQUEST` after security validation)
     */
    public static function fromRequest(array $raw): self
    {
        return new self(
            country: TypeCoerce::toString($raw['country'] ?? ''),
            region: TypeCoerce::toString($raw['region'] ?? ''),
            city: TypeCoerce::toString($raw['city'] ?? ''),
            freeText: TypeCoerce::toString($raw['destination'] ?? ''),
        );
    }

    public function isEmpty(): bool
    {
        return $this->country === ''
            && $this->region === ''
            && $this->city === ''
            && $this->freeText === '';
    }

    /**
     * @return array{country: string, region: string, city: string, freeText: string}
     */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'region' => $this->region,
            'city' => $this->city,
            'freeText' => $this->freeText,
        ];
    }
}
