<?php

namespace App\Models;

use Database\Factories\ProjectPartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Party taking part in one Project (M3.4, D-092, D-098).
 *
 * **Three fields are fillable and three are not**, and the split is the D-098
 * mutation boundary expressed where it cannot be forgotten:
 *
 *   role_code    is_primary    notes          may be corrected
 *   project_id   party_id      office_id      may never change
 *
 * Re-pointing a participation at a different Project or Party is not an edit of
 * that participation — it is a different relationship, and pretending otherwise
 * would let one row silently become another while keeping its `created_by` and
 * `created_at`. `office_id` is the constraint carrier the composite foreign keys
 * check against, so it is not data anybody may set either.
 *
 * **No timestamps beyond `created_at`.** `$timestamps` is off because the table
 * has no `updated_at` column: `03_DATABASE_ERD.md` section 7 does not give it
 * one, and this table is current working state rather than the historical ledger
 * `company_people` is (D-083 vs D-098). `created_at` is stamped explicitly by
 * the Action.
 *
 * **No soft delete.** Removing a participation deletes the row. There is no
 * `deleted_at` to restore from and no claim that historical participation is
 * preserved — saying otherwise while keeping no history would be the worse of
 * the two failures.
 *
 * `role_code` has **no enum cast**, deliberately. No canonical participant-role
 * vocabulary exists; the ERD's six codes are labelled examples, not a catalogue.
 * Casting to an enum would invent the legal role list M3 has no authority to
 * write (CLAUDE.md section 62).
 */
#[Fillable([
    'role_code',
    'is_primary',
    'notes',
])]
class ProjectParty extends Model
{
    /** @use HasFactory<ProjectPartyFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'project_parties';

    /**
     * The table has `created_at` and no `updated_at`, so Eloquent's automatic
     * pair would fail on write. The Action stamps `created_at` itself.
     */
    public $timestamps = false;

    /**
     * Mirrors the column default deliberately.
     *
     * Without it a participation created without `is_primary` serialized as
     * `null` in its own 201 response while the database stored `false` — the
     * creating client and a later reader would have been told different things
     * about the same row. The database default still exists and is still
     * authoritative for anything written outside Eloquent; this makes the
     * in-memory object agree with it before the round trip.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_primary' => false,
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
