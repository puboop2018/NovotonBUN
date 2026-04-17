<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

/**
 * Circuit repository — wraps sphinx_circuits table.
 *
 * @since 1.2.0
 */
class CircuitRepository
{
    public function exists(int $circuitId): bool
    {
        return (bool) db_get_field(
            'SELECT circuit_id FROM ?:sphinx_circuits WHERE circuit_id = ?i',
            $circuitId,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $circuitId, array $data): void
    {
        db_query('UPDATE ?:sphinx_circuits SET ?u WHERE circuit_id = ?i', $data, $circuitId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): void
    {
        db_query('INSERT INTO ?:sphinx_circuits ?e', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsert(int $circuitId, array $data): void
    {
        if ($this->exists($circuitId)) {
            $this->update($circuitId, $data);
        } else {
            $data['circuit_id'] = $circuitId;
            $this->insert($data);
        }
    }
}
