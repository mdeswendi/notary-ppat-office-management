<?php

namespace App\Models;

use Database\Factories\PropertyOwnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One link in a chain of title (M7.1, D-121).
 *
 * **History is added, never overwritten** (`CLAUDE.md` section 63). Transferring
 * ownership closes the previous row — stamping `effective_until` and clearing
 * `is_current` — and inserts a new one. It never rewrites the old row's party or
 * percentage, which is what makes this table an audit trail rather than a current
 * state somebody keeps editing.
 *
 * **`is_current` is a flag on many rows, not a pointer to one.** A Property
 * legitimately has several current owners at once, each with a percentage, so
 * D-116's ruling against `is_current` on `document_versions` does not apply — see the
 * migration for the full argument. There is deliberately no unique index on
 * `(property_id, is_current)`, and adding one would break co-ownership.
 *
 * What *is* guarded is the contradiction: a row that has ended cannot also be
 * current. A PostgreSQL CHECK enforces it and this model holds the same rule on the
 * SQLite connection the suite runs on.
 *
 * **No percentage sum is enforced.** Whether co-owners must total 100 is a rule about
 * Indonesian co-ownership, and `CLAUDE.md` section 62 forbids inventing it.
 */
#[Fillable([
    'ownership_percentage',
    'effective_from',
    'effective_until',
    'is_current',
])]
class PropertyOwner extends Model
{
    /** @use HasFactory<PropertyOwnerFactory> */
    use HasFactory;

    use HasUlids;

    protected static function booted(): void
    {
        // Each of these is also a PostgreSQL CHECK. The guards are what hold the same
        // rules on the SQLite connection the suite runs on, so a contradictory row
        // fails in the tests rather than only in production.
        static::saving(function (self $owner): void {
            if ($owner->is_current && $owner->effective_until !== null) {
                throw new RuntimeException(
                    'property_owners: a row that has ended cannot also be current (M7.1). '
                    .'is_current is derivable from effective_until, and the two must not disagree: '
                    .'closing a link clears the flag and stamps the date together.'
                );
            }

            $percentage = $owner->ownership_percentage;

            if ($percentage !== null && ((float) $percentage < 0 || (float) $percentage > 100)) {
                throw new RuntimeException(
                    'property_owners.ownership_percentage is a percentage (M7.1). '
                    .'This is arithmetic, not a legal rule: no sum across co-owners is enforced, '
                    .'because whether shares must total 100 is a question about Indonesian '
                    .'co-ownership that no canonical document answers.'
                );
            }

            if ($owner->effective_until !== null && $owner->effective_from !== null
                && $owner->effective_until->lt($owner->effective_from)) {
                throw new RuntimeException(
                    'property_owners: a period runs forwards (M7.1). '
                    .'effective_until must not precede effective_from.'
                );
            }
        });

        static::updating(function (self $owner): void {
            foreach (['property_id', 'party_id', 'office_id'] as $attribute) {
                if ($owner->isDirty($attribute)) {
                    throw new RuntimeException(
                        "property_owners.{$attribute} is immutable (M7.1, D-121). "
                        .'A chain of title is corrected by closing a link and adding another, never '
                        .'by rewriting who owned what (CLAUDE.md section 63).'
                    );
                }
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * The transaction that produced this link.
     *
     * Nullable: an office records the ownership it inherits with the file, which
     * predates any Matter in this system.
     */
    public function sourceMatter(): BelongsTo
    {
        return $this->belongsTo(Matter::class, 'source_matter_id');
    }

    protected function casts(): array
    {
        return [
            'ownership_percentage' => 'decimal:2',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_current' => 'boolean',
        ];
    }
}
