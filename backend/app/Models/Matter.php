<?php

namespace App\Models;

use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\Enums\MatterStatus;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Project\Enums\ProjectPriority;
use Database\Factories\MatterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * The operational unit of work inside a Project (M4.2, D-107).
 *
 * **Not fillable, each for its own reason** — the D-091 mutation boundary
 * expressed where it cannot be forgotten:
 *
 * ```text
 *   project_id      parentage, fixed at creation
 *   office_id       the security boundary, inherited from the Project (D-099)
 *   domain          decides which capability namespace authorizes this record
 *   status          answers to `*.matters.complete` / `.cancel` / a status
 *                   capability, never to ordinary update
 *   pic_user_id     answers to `*.matters.assign`, never to ordinary update
 *   created_by      actor metadata, written by the application
 *   updated_by      actor metadata, never request input
 *   service_type_id classification, set at creation by a path M4.4 owns
 * ```
 *
 * A future `UpdateMatter` Action accepting a request body therefore cannot
 * reassign a Matter, move its status, or move it between Offices by accident:
 * the model refuses the fields rather than trusting the Action to filter them.
 *
 * **Three fields are identity and are refused even to `forceFill`.** `project_id`,
 * `office_id`, and `domain` are guarded in `updating()` because `Fillable` alone
 * does not stop a direct attribute assignment. Office is the security boundary
 * and M4 designs no transfer (D-099); `domain` selects the permission namespace
 * (D-101), so flipping it would reclassify work already done; and re-parenting a
 * Matter would move it between Projects whose Offices may differ, which the
 * composite foreign key would refuse anyway — better to say why than to let a
 * database error explain it.
 *
 * **No `SoftDeletes`, deliberately** (D-102). The table carries `deleted_at` as
 * reserved schema capability because the ERD lists it, but M4 ships no archive or
 * restore lifecycle and the canonical registry defines no code that could
 * authorize one. Adding the trait would install a global scope that silently
 * filters every query — including {@see MatterVisibility} —
 * making "invisible because soft-deleted" indistinguishable from "unreachable by
 * scope", and would settle visibility semantics before the milestone that owns
 * archiving exists to settle them.
 *
 * `ARCHIVED` is a **business status**, never soft deletion.
 *
 * **`matter_number` arrived at M4.3** with its allocator (D-103), and is
 * **immutable once the row exists** — see the guard below. It is nullable until
 * M4.4 integrates allocation into the creating transaction and tightens the
 * column. **`current_stage_id` is still deferred to M4.7**, with the real
 * stage-instance foreign key; it is not stubbed here.
 *
 * No participant collection either — `matter_parties` belongs to M4.5 (D-105) —
 * and no workflow relation, which is M4.6 and M4.7.
 */
#[Fillable([
    'title',
    'priority',
    'opened_at',
    'target_completion_date',
    'completed_at',
    'notes',
])]
class Matter extends Model
{
    /** @use HasFactory<MatterFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Refuse an identity change before it reaches SQL.
     */
    protected static function booted(): void
    {
        static::updating(function (self $matter): void {
            foreach (['project_id', 'office_id', 'domain'] as $attribute) {
                if ($matter->isDirty($attribute)) {
                    throw new RuntimeException(
                        "matters.{$attribute} is immutable during M4 (D-099, D-101, D-107). "
                        .'Parentage, Office ownership, and domain are identity rather than content: '
                        .'the Office is a security boundary, the domain selects the capability '
                        .'namespace that authorizes this record, and re-parenting would move work '
                        .'between Offices. Lifting any of them needs its own architecture decision.'
                    );
                }
            }

            // The internal reference is allocated once and then belongs to the
            // record (D-103). **Every** change is refused, including `null -> a
            // reference`: M4.4 stamps the reference inside the creating
            // transaction, so a Matter is never created bare and numbered
            // afterwards. That is stricter than the Project guard, which had to
            // permit `null -> reference` while M3.2's column was nullable and
            // dropped the branch at M3.3; Matter can start strict because its
            // create path does not exist yet to have relied on the looser rule.
            //
            // This fires on `updating` only, so it never blocks the stamp itself:
            // an allocation lands on a new model, which is an insert.
            if ($matter->isDirty('matter_number')) {
                throw new RuntimeException(
                    'matters.matter_number is immutable once the row exists (D-103). '
                    .'A reference belongs to the Matter that received it, and one is stamped '
                    .'during creation rather than added by a later update.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * The optional classification. Null is a complete Matter, not a draft
     * (D-102): no validated service catalogue exists yet.
     *
     * @return BelongsTo<ServiceType, $this>
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * The person in charge — the `ASSIGNED` Data Scope predicate (D-100).
     *
     * @return BelongsTo<User, $this>
     */
    public function picUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    /**
     * The creator — the `OWN` Data Scope predicate (D-100).
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array<string, string>
     */
    /**
     * Documents attached to this Matter (M5.3, D-118).
     *
     * **A relationship, not ownership.** The junction carries an `office_id`
     * constraint carrier written from the Document, and composite foreign keys
     * make the same-Office agreement structural rather than validated (D-116).
     *
     * `attached_at` and `attached_by` are the only pivot columns read. `office_id`
     * is a constraint carrier, never information.
     *
     * **Reading this relation is not authorization**, and it matters more here
     * than anywhere: which of these rows a caller may see answers to
     * `documents.view` and its Data Scope, not to `notary.matters.view`. Folding
     * documents into the Matter payload would have made a Matter capability a way
     * to read what has been filed — which is why the document section on the
     * Matter page asks its own endpoint instead.
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'matter_documents')
            ->withPivot(['attached_at', 'attached_by']);
    }

    /**
     * The Notary-specific classification of this Matter (M6.1, D-120).
     *
     * **Present only on `NOTARY` Matters, and optional even there** — a Matter with
     * no extension row is classified as nothing in particular, which is the correct
     * state while `deed_category` has no catalogue. Nothing branches on
     * `requires_minuta` or `requires_register_entry`; see `NotaryMatter`.
     */
    public function notaryExtension(): HasOne
    {
        return $this->hasOne(NotaryMatter::class, 'matter_id');
    }

    /**
     * The Notarial Deeds produced by this Matter (M6.1, D-120).
     *
     * **Reading this relation is not authorization.** Which of these a caller may
     * see answers to `notary.deeds.view` and its own Data Scope, never to
     * `notary.matters.view` — reaching a Matter confers no Deed authority, the
     * symmetric statement of D-100. The deed section on the Matter page asks its own
     * endpoint, exactly as the document section does.
     */
    public function notaryDeeds(): HasMany
    {
        return $this->hasMany(NotaryDeed::class);
    }

    /**
     * The PPAT-specific classification of this Matter (M7.1, D-121).
     *
     * Present only on `PPAT` Matters, and optional even there. Nothing branches on
     * `tax_processing_required` or `registration_required`; see {@see PpatMatter}.
     */
    public function ppatExtension(): HasOne
    {
        return $this->hasOne(PpatMatter::class, 'matter_id');
    }

    /**
     * The PPAT Deeds produced by this Matter (M7.1, D-121).
     *
     * **Reading this relation is not authorization.** Which of these a caller may see
     * answers to `ppat.deeds.view` and its own Data Scope, never to
     * `ppat.matters.view` — reaching a Matter confers no Deed authority, the
     * symmetric statement of D-100.
     */
    public function ppatDeeds(): HasMany
    {
        return $this->hasMany(PpatDeed::class);
    }

    /**
     * The land objects this Matter concerns (M7.1, D-121).
     *
     * A Property is office-owned reference data that predates the Matter; this
     * junction records the role it plays in *this* transaction. Reading it answers to
     * `properties.view`, not to the Matter capability.
     */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'matter_properties')
            ->withPivot(['role_code', 'office_id']);
    }

    protected function casts(): array
    {
        return [
            'domain' => MatterDomain::class,
            'status' => MatterStatus::class,

            // Reused rather than duplicated: `ProjectPriority` records that
            // `03_DATABASE_ERD.md` names `priority` on projects, matters, and
            // tasks and defines the vocabulary exactly once. One shared
            // vocabulary, one enum — a `MatterPriority` with identical values
            // would be duplication that can drift, and refactoring the accepted
            // M3 enum's ownership for naming elegance would touch closed work.
            'priority' => ProjectPriority::class,

            'opened_at' => 'date',
            'target_completion_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }
}
