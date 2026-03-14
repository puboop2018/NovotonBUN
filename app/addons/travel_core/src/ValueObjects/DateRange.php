<?php
declare(strict_types=1);
/**
 * DateRange Value Object
 *
 * Encapsulates a check-in / check-out date pair with derived calculations.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\ValueObjects;

use Tygh\Addons\TravelCore\Exceptions\InvalidArgumentException;

final class DateRange
{
    /** @var string Y-m-d */
    private $checkIn;

    /** @var string Y-m-d */
    private $checkOut;

    /** @var int */
    private $nights;

    private function __construct(string $checkIn, string $checkOut, int $nights)
    {
        $this->checkIn  = $checkIn;
        $this->checkOut = $checkOut;
        $this->nights   = $nights;
    }

    public static function fromCheckInAndNights(string $checkIn, int $nights): self
    {
        $ts = strtotime($checkIn);
        if ($ts === false) {
            throw new InvalidArgumentException("Invalid check-in date: {$checkIn}");
        }
        if ($nights < 1 || $nights > 30) {
            throw new InvalidArgumentException("Nights must be between 1 and 30, got: {$nights}");
        }

        $checkInDate  = date('Y-m-d', $ts);
        $checkOutDate = date('Y-m-d', strtotime("+{$nights} days", $ts));

        return new self($checkInDate, $checkOutDate, $nights);
    }

    public static function fromDates(string $checkIn, string $checkOut): self
    {
        $tsIn  = strtotime($checkIn);
        $tsOut = strtotime($checkOut);

        if ($tsIn === false) {
            throw new InvalidArgumentException("Invalid check-in date: {$checkIn}");
        }
        if ($tsOut === false) {
            throw new InvalidArgumentException("Invalid check-out date: {$checkOut}");
        }
        if ($tsOut <= $tsIn) {
            throw new InvalidArgumentException("Check-out ({$checkOut}) must be after check-in ({$checkIn})");
        }

        $nights = (new \DateTime(date('Y-m-d', $tsIn)))->diff(new \DateTime(date('Y-m-d', $tsOut)))->days;

        return new self(date('Y-m-d', $tsIn), date('Y-m-d', $tsOut), $nights);
    }

    public function checkIn(): string  { return $this->checkIn; }
    public function checkOut(): string { return $this->checkOut; }
    public function nights(): int      { return $this->nights; }

    public function shift(int $days): self
    {
        return self::fromCheckInAndNights(
            date('Y-m-d', strtotime("{$days} days", strtotime($this->checkIn))),
            $this->nights
        );
    }

    public function contains(string $date): bool
    {
        return $date >= $this->checkIn && $date < $this->checkOut;
    }

    public function overlaps(self $other): bool
    {
        return $this->checkIn < $other->checkOut && $other->checkIn < $this->checkOut;
    }

    public function equals(self $other): bool
    {
        return $this->checkIn === $other->checkIn && $this->checkOut === $other->checkOut;
    }

    public function __toString(): string
    {
        return "{$this->checkIn} → {$this->checkOut} ({$this->nights}n)";
    }
}
