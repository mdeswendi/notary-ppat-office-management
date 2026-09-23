<?php

namespace App\Domains\Ppat;

use App\Models\Office;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;

/**
 * Allocates the next Property reference for an Office in one atomic statement.
 *
 * The sequence does not reset annually because a parcel is permanent office
 * reference data. Database-side upsert avoids duplicate numbers when two users
 * create Properties at the same time.
 */
class AllocatePropertyReference
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function forOffice(Office|string $office): string
    {
        $officeId = $office instanceof Office ? $office->getKey() : $office;

        return PropertyReference::format($this->nextValue($officeId));
    }

    public function nextValue(string $officeId): int
    {
        $now = Date::now()->toDateTimeString();
        $table = 'property_reference_counters';

        $sql = <<<SQL
            INSERT INTO {$table} (office_id, last_value, created_at, updated_at)
            VALUES (?, 1, ?, ?)
            ON CONFLICT (office_id)
            DO UPDATE SET last_value = {$table}.last_value + 1, updated_at = ?
            RETURNING last_value
            SQL;

        $row = $this->connection->selectOne($sql, [$officeId, $now, $now, $now]);

        if ($row === null || ! isset($row->last_value)) {
            throw new \RuntimeException(
                "Property reference allocation returned no value for office {$officeId}."
            );
        }

        return (int) $row->last_value;
    }
}
