<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The legal-office group this installation manages.
 *
 * V1 runs one active Organization per deployment (D-026). That is enforced by
 * application rules, not by the schema, so nothing here assumes a single row.
 *
 * Lives in `app/Models` alongside `User` rather than under
 * `app/Domains/Identity`, so the identity models stay in one place. Relocating
 * the whole set is a deliberate refactor, not M1.1 work.
 *
 * No global scope, no tenant scope, no policy — those arrive with the
 * milestones that need them.
 */
#[Fillable(['name', 'legal_name', 'timezone', 'default_locale'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * ULID primary key per D-023. `is_active` is left out of the fillable list:
     * retiring an Organization is an administrative act, never something a
     * request body should be able to flip.
     */
    use HasUlids;

    /**
     * @return HasMany<Office, $this>
     */
    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
