<?php
declare(strict_types=1);
/**
 * DateRange Value Object
 *
 * Encapsulates a check-in / check-out date pair with derived calculations
 * (number of nights, formatted output). Guarantees that check-out is after
 * check-in and both dates are valid.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\ValueObjects;

use Tygh\Addons\NovotonHolidays\Exceptions\InvalidArgumentException;

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

    /**
     * Create from check-in date and number of nights.
     *
     * @param string $checkIn Y-m-d formatted date
     * @param int    $nights  Number of nights (1–30)
     * @return self
     * @throws InvalidArgumentException
     */
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

    /**
     * Create from check-in and check-out dates.
     *
     * @param string $checkIn  Y-m-d
     * @param string $checkOut Y-m-d
     * @return self
     * @throws InvalidArgumentException
     */
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

    public function checkIn(): string
    {
        return $this->checkIn;
    }

    public function checkOut(): string
    {
        return $this->checkOut;
    }

    public function nights(): int
    {
        return $this->nights;
    }

    /**
     * Shift the range by N days (positive or negative).
     */
    public function shift(int $days): self
    {
        return self::fromCheckInAndNights(
            date('Y-m-d', strtotime("{$days} days", strtotime($this->checkIn))),
            $this->nights
        );
    }

    /**
     * Check if a given date falls within this range (inclusive check-in, exclusive check-out).
     */
    public function contains(string $date): bool
    {
        return $date >= $this->checkIn && $date < $this->checkOut;
    }

    /**
     * Check if two date ranges overlap.
     */
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
