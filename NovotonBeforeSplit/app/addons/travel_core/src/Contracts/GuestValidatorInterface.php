<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Guest Validator Interface
 *
 * Responsible for validating guest data against expected counts and rules.
 */
interface GuestValidatorInterface
{
    /**
     * Validate guests data
     *
     * @param array $guests_data Guests data
     * @param int $expected_adults Expected adult count
     * @param int $expected_children Expected children count
     * @return array{valid: bool, errors: string[], adults: int, children: int}
     */
    public function validate(array $guests_data, int $expected_adults = 0, int $expected_children = 0): array;
}
