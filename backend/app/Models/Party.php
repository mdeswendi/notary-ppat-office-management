<?php

namespace App\Models;

use App\Domains\Party\Enums\PartyType;
use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * The aggregate root for every person and organization the office knows (D-078).
 *
 * `office_id`, `party_type`, `created_by`, and `updated_by` are deliberately not
 * fillable. Office ownership is the security boundary, `party_type` is immutable,
 * and actor metadata is written by the application — none may arrive from request
 * input (docs/07_SECURITY_RULES.md section 34).
 *
 * `display_name` is fillable but derived: the Create and Update Actions that M2.2
 * and M2.3 introduce own its synchronization with the subtype name, atomically
 * (D-079). It is not a third independently editable name, and no observer here
 * quietly rewrites it — the project uses explicit Actions, and a silent observer
 * would hide the very transaction boundary that keeps the two in step.
 */
#[Fillable([
    'display_name',
    'primary_phone',
    'primary_email',
])]
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Archiving is an aggregate operation and this is where it lives (D-081).
     * Subtypes are never independently soft-deleted; a live Party root with an
     * archived subtype is a state nothing could render honestly.
     */
    use SoftDeletes;

    /**
     * Refuse a `party_type` change before it reaches SQL.
     *
     * The database already refuses it while a subtype row exists — the composite
     * foreign key from `individuals` / `companies` sees to that. This guard exists
     * for the two things that constraint cannot give: a Party that has no subtype
     * yet (mid-transaction, or an orphan defect), and an error message that says
     * what went wrong instead of surfacing a foreign-key violation.
     */
    protected static function booted(): void
    {
        static::updating(function (self $party): void {
            if ($party->isDirty('party_type')) {
                throw new RuntimeException(
                    'party_type is immutable (D-078). Archive the record and create the correct type instead.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * @return HasOne<Individual, $this>
     */
    public function individual(): HasOne
    {
        return $this->hasOne(Individual::class, 'party_id');
    }

    /**
     * @return HasOne<Company, $this>
     */
    public function company(): HasOne
    {
        return $this->hasOne(Company::class, 'party_id');
    }

    /**
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

    public function isIndividual(): bool
    {
        return $this->party_type === PartyType::INDIVIDUAL;
    }

    public function isCompany(): bool
    {
        return $this->party_type === PartyType::COMPANY;
    }

    /**
     * @return array<string, string>
     */
    /**
     * Documents attached to this Party (M5.3, D-118).
     *
     * **A relationship, not ownership.** The junction carries an `office_id`
     * constraint carrier written from the Document, and composite foreign keys
     * make the same-Office agreement structural rather than validated (D-116).
     *
     * `attached_at` and `attached_by` are the only pivot columns read. `office_id`
     * is a constraint carrier, never information: exposing it as pivot data would
     * invite somebody to treat it as a third opinion about which Office owns what.
     *
     * **Reading this relation is not authorization.** Which of these rows a caller
     * may see answers to `documents.view` and its Data Scope, which the Document
     * surface applies — reaching a Party confers no document access.
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'party_documents')
            ->withPivot(['attached_at', 'attached_by']);
    }

    protected function casts(): array
    {
        return [
            'party_type' => PartyType::class,
        ];
    }
}
