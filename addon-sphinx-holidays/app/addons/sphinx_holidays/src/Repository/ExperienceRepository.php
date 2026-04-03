<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

/**
 * Experience repository — wraps sphinx_experiences table.
 *
 * @since 1.2.0
 */
class ExperienceRepository
{
    public function exists(int $experienceId): bool
    {
        return (bool) db_get_field(
            "SELECT experience_id FROM ?:sphinx_experiences WHERE experience_id = ?i",
            $experienceId
        );
    }

    public function update(int $experienceId, array $data): void
    {
        db_query("UPDATE ?:sphinx_experiences SET ?u WHERE experience_id = ?i", $data, $experienceId);
    }

    public function insert(array $data): void
    {
        db_query("INSERT INTO ?:sphinx_experiences ?e", $data);
    }

    public function upsert(int $experienceId, array $data): void
    {
        if ($this->exists($experienceId)) {
            $this->update($experienceId, $data);
        } else {
            $data['experience_id'] = $experienceId;
            $this->insert($data);
        }
    }
}
