<?php
declare(strict_types=1);
/**
 * Sphinx Security Service
 *
 * Implements travel_core's SecurityServiceInterface with Sphinx-specific
 * validation rules for booking data, search parameters, and guest data.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Repository\SphinxCacheRepository;
use Tygh\Addons\TravelCore\Contracts\SecurityServiceInterface;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;
use Tygh\Addons\TravelCore\TravelConstants;

class SecurityService implements SecurityServiceInterface
{
    /** @var int Rate limit window in seconds */
    private const RATE_LIMIT_WINDOW = 3600;

    /** @var int Max bookings per hour */
    private const MAX_BOOKINGS_PER_HOUR = 20;

    /**
     * {@inheritdoc}
     */
    public function validateBookingData(array $data): array
    {
        $errors = [];

        $required = ['hotel_id', 'offer_id', 'check_in', 'check_out'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        if (!empty($data['check_in']) && !$this->isValidDate($data['check_in'])) {
            $errors[] = 'Invalid check-in date format';
        }

        if (!empty($data['check_out']) && !$this->isValidDate($data['check_out'])) {
            $errors[] = 'Invalid check-out date format';
        }

        if (!empty($data['check_in']) && !empty($data['check_out'])
            && strtotime($data['check_out']) <= strtotime($data['check_in'])
        ) {
            $errors[] = 'Check-out must be after check-in';
        }

        if (isset($data['adults'])) {
            $adults = (int)$data['adults'];
            if ($adults < 1 || $adults > TravelConstants::MAX_ADULTS) {
                $errors[] = 'Adults must be between 1 and ' . TravelConstants::MAX_ADULTS;
            }
        }

        if (isset($data['children'])) {
            $children = (int)$data['children'];
            if ($children < 0 || $children > TravelConstants::MAX_CHILDREN) {
                $errors[] = 'Children must be between 0 and ' . TravelConstants::MAX_CHILDREN;
            }
        }

        if (isset($data['total_price'])) {
            $price = (float)$data['total_price'];
            if ($price < 0 || $price > 100000) {
                $errors[] = 'Invalid price value';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function validateSearchParams(array $params): array
    {
        $sanitized = [];

        if (!empty($params['check_in']) && $this->isValidDate($params['check_in'])) {
            $sanitized['check_in'] = $params['check_in'];
        }

        if (!empty($params['check_out']) && $this->isValidDate($params['check_out'])) {
            $sanitized['check_out'] = $params['check_out'];
        }

        $sanitized['nights'] = max(1, min(TravelConstants::MAX_NIGHTS, (int)($params['nights'] ?? TravelConstants::DEFAULT_NIGHTS)));
        $sanitized['adults'] = max(1, min(TravelConstants::MAX_ADULTS, (int)($params['adults'] ?? TravelConstants::DEFAULT_ADULTS)));
        $sanitized['children'] = max(0, min(TravelConstants::MAX_CHILDREN, (int)($params['children'] ?? TravelConstants::DEFAULT_CHILDREN)));

        if (!empty($params['destination'])) {
            $sanitized['destination'] = mb_substr(strip_tags($params['destination']), 0, 100);
        }

        if (!empty($params['hotel_id'])) {
            $sanitized['hotel_id'] = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($params['hotel_id'], 0, 50));
        }

        if (!empty($params['children_ages']) && is_string($params['children_ages'])) {
            $sanitized['children_ages'] = preg_replace('/[^0-9.,]/', '', $params['children_ages']);
        }

        return $sanitized;
    }

    /**
     * {@inheritdoc}
     */
    public function checkBookingRateLimit(string $identifier): bool
    {
        $cacheKey = 'sphinx_rate_booking_' . md5($identifier);
        $now = time();

        $cacheRepo = new SphinxCacheRepository();
        $row = $cacheRepo->findByKey($cacheKey);
        $data = ($row && (int) $row['expires_at'] > $now) ? $row['cache_data'] : null;

        $record = $data ? json_decode($data, true) : null;

        if (!is_array($record) || ($record['reset'] ?? 0) <= $now) {
            $record = ['count' => 0, 'reset' => $now + self::RATE_LIMIT_WINDOW];
        }

        if ($record['count'] >= self::MAX_BOOKINGS_PER_HOUR) {
            return false;
        }

        $record['count']++;

        $cacheRepo->upsert(
            $cacheKey,
            json_encode($record, JSON_UNESCAPED_UNICODE) ?: '',
            $record['reset'] + 60
        );

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function sanitizeGuestData(array $guests): array
    {
        $sanitized = [];

        foreach ($guests as $key => $guest) {
            if (!is_array($guest)) {
                continue;
            }

            $sanitized[$key] = [
                'first_name' => $this->sanitizeName($guest['first_name'] ?? ''),
                'last_name' => $this->sanitizeName($guest['last_name'] ?? ''),
                'name' => $this->sanitizeName($guest['name'] ?? ''),
                'type' => in_array($guest['type'] ?? '', ['adult', 'child']) ? $guest['type'] : 'adult',
                'age' => isset($guest['age']) ? max(0, min(99, (int)$guest['age'])) : null,
                'room' => max(1, min(5, (int)($guest['room'] ?? 1))),
                'is_holder' => !empty($guest['is_holder']),
            ];

            if (!empty($guest['birthday']) && $this->isValidDate($guest['birthday'])) {
                $sanitized[$key]['birthday'] = $guest['birthday'];
            }
        }

        return $sanitized;
    }

    private function isValidDate(string $date): bool
    {
        return ValidationHelpers::isValidDate($date);
    }

    private function sanitizeName(string $name): string
    {
        return ValidationHelpers::sanitizeName($name);
    }
}
