<?php

namespace App\Models;

use App\Domains\Party\Enums\CompanyRelationshipCategory;
use App\Domains\Party\Enums\CompanyRelationshipType;
use Database\Factories\CompanyPersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Individual's relationship to one Company, over a period (D-083).
 *
 * Schema foundation only. M2.4 owns management of these rows; nothing in M2.1
 * creates or mutates one outside tests.
 *
 * `office_id` is not fillable and is not independent data — it is the constraint
 * carrier the composite foreign keys use to make both endpoints share an Office.
 * A cross-office relationship is unrepresentable at the database level, not
 * merely discouraged (D-080).
 *
 * No person or company name is stored here. The relationship points at the
 * Party, so a rename stays correct everywhere.
 *
 * **Current-ness is a query, never a column.** `effective_until IS NULL` means
 * current; there is deliberately no `is_current` flag to drift out of agreement
 * with the dates it would summarize (D-081).
 */
#[Fillable([
    'relationship_type',
    'position_name',
    'ownership_percentage',
    'effective_from',
    'effective_until',
])]
class CompanyPerson extends Model
{
    /** @use HasFactory<CompanyPersonFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'company_people';

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_party_id', 'party_id');
    }

    /**
     * @return BelongsTo<Individual, $this>
     */
    public function individual(): BelongsTo
    {
        return $this->belongsTo(Individual::class, 'individual_party_id', 'party_id');
    }

    /**
     * Which authorization surface governs this row — management or ownership
     * (D-083). Derived from the type, so the mapping exists in exactly one place.
     */
    public function category(): CompanyRelationshipCategory
    {
        return $this->relationship_type->category();
    }

    /**
     * Relationships in force: no end date recorded.
     *
     * @param  Builder<CompanyPerson>  $query
     * @return Builder<CompanyPerson>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('effective_until');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relationship_type' => CompanyRelationshipType::class,
            'ownership_percentage' => 'decimal:4',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }
}
