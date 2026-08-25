<?php

namespace App\Models;

use App\Domains\Ppat\Enums\PropertyType;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A land object (M7.1, D-121).
 *
 * **Office-owned reference data, not work.** A Property exists before any Matter
 * names it and outlives every one of them, which is why its Data Scope is `OFFICE`
 * and `ALL` only — the Party (D-080) and Service Type (D-106) answer rather than the
 * Project (D-088) one.
 *
 * **`office_id` is immutable.** It is the security boundary and the `OFFICE`
 * predicate, and moving a parcel between Offices would strand every composite key
 * holding its owners, Matters and Warkah lines to that Office.
 *
 * **`property_number` is immutable once assigned**, following `matter_number`
 * (D-103) and `document_number` (D-116): a reference belongs to the record that
 * received it. It is nullable at M7.1 because no creation path exists to allocate one
 * — the allocator arrives with the creation surface at M7.3, exactly as M4.3 followed
 * M4.2 and M5.2 followed M5.1.
 *
 * **`status` is not fillable and nothing writes it.** The ERD names the column and
 * gives it no values; `properties.archive` is a canonical capability whose meaning is
 * undefined until somebody says what archiving a land object does.
 *
 * `SoftDeletes` **is** used here, unlike `notary_deeds` and `ppat_deeds`: the ERD
 * carries `deleted_at` for this table, and a Property is reference data an office may
 * retire rather than a finalized legal record.
 */
#[Fillable([
    'property_type',
    'right_type',
    'certificate_number',
    'certificate_date',
    'land_area',
    'building_area',
    'measurement_letter_number',
    'measurement_letter_date',
    'address',
    'village',
    'district',
    'city',
    'province',
    'postal_code',
    'latitude',
    'longitude',
])]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (self $property): void {
            if ($property->isDirty('office_id')) {
                throw new RuntimeException(
                    'properties.office_id is immutable (M7.1). '
                    .'Office is the security boundary and the OFFICE scope predicate, so moving a '
                    .'Property between Offices would silently redefine who may see it, and would '
                    .'strand every composite key holding its owners, Matters and Warkah lines.'
                );
            }

            // Permits null to a reference, because M7.3 stamps it on a record that
            // already exists; refuses every change after that. The M3.2 shape, which
            // M4 tightened once creation allocated inline.
            if ($property->isDirty('property_number') && $property->getOriginal('property_number') !== null) {
                throw new RuntimeException(
                    'properties.property_number is immutable once assigned (M7.1, D-103). '
                    .'A reference belongs to the record that received it.'
                );
            }
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * Who recorded the parcel (M7.3).
     *
     * Attribution only — **not** a Data Scope predicate. `PropertyVisibility` applies
     * `OFFICE` and `ALL` alone, so unlike `Matter::createdBy()` this relation confers
     * nothing: the colleague who typed in a parcel has no claim on it (D-080, D-106).
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who last corrected it (M7.3). Attribution only, as above.
     *
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The whole chain of title, newest first.
     *
     * History is added and never overwritten (`CLAUDE.md` section 63), so this is the
     * audit trail rather than a mutable list.
     */
    public function owners(): HasMany
    {
        return $this->hasMany(PropertyOwner::class)->orderByDesc('effective_from');
    }

    /**
     * Who owns it now, possibly several parties at once.
     *
     * **Not a single owner.** Co-ownership is ordinary, which is why
     * `property_owners.is_current` is a flag on many rows rather than the pointer
     * D-116 replaced `document_versions.is_current` with. A singular `currentOwner()`
     * would misrepresent a jointly-held parcel, so this relation is deliberately
     * plural.
     */
    public function currentOwners(): HasMany
    {
        return $this->hasMany(PropertyOwner::class)->where('is_current', true);
    }

    /**
     * The Matters that concern this Property.
     *
     * **Reading this relation is not authorization.** Which of these a caller may see
     * answers to `ppat.matters.view` or `notary.matters.view` and their own Data
     * Scope, never to `properties.view` — a Matter is reached at its own domain root
     * (D-101).
     */
    public function matters(): BelongsToMany
    {
        return $this->belongsToMany(Matter::class, 'matter_properties')
            ->withPivot(['role_code', 'office_id']);
    }

    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'certificate_date' => 'date',
            'measurement_letter_date' => 'date',
            'land_area' => 'decimal:2',
            'building_area' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}
