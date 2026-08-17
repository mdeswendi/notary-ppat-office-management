<?php

namespace App\Models;

use App\Domains\MasterData\Enums\ServiceTypeDomain;
use Database\Factories\ServiceTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One entry in an Office's own catalogue of the work it offers (M4.1, D-102).
 *
 * **Three fields are identity, not content**, and none of them is fillable:
 *
 *   office_id   the security boundary and the OFFICE scope predicate
 *   code        the stable handle other records classify themselves by
 *   domain      NOTARY or PPAT, and a Matter surface is chosen by it (D-101)
 *
 * The rest — both names, both descriptions, ordering, and the default duration —
 * is ordinary master-data content that an office may correct at will.
 *
 * That split is the whole design. Changing a name fixes a label; changing a
 * `code`, a `domain`, or an Office silently redefines what every record already
 * pointing at this row means. `updating()` refuses all three rather than trusting
 * a future Action to filter them, because `Fillable` alone does not stop
 * `forceFill` or a direct attribute assignment.
 *
 * **Retirement is `is_active`, and there is no other lifecycle.** No soft delete,
 * no archive, no restore — and no canonical permission that could authorize one,
 * since the registry offers only `master.services.view` and
 * `master.services.manage`. `is_active` is deliberately **not** fillable either:
 * deactivating a service withdraws it from every future selection, which is a
 * different act from correcting its description, and it gets its own mutation
 * boundary when a write surface exists (the D-091 shape). M4.1 ships no such
 * surface, so nothing sets it except a factory state and a test.
 *
 * **A record referencing an inactive Service Type keeps its reference.** Inactive
 * means unavailable for new selection, never erased from history (CLAUDE.md
 * section 63) — which is also why deletion is not the retirement strategy and why
 * M4.2's `matters.service_type_id` must never be designed as `SET NULL`.
 *
 * No Matter relation exists here and none may be added before M4.2. Matter will
 * reference Service Type, not the reverse, and no workflow relation exists either
 * — templates belong to M4.6.
 */
#[Fillable([
    'name_id',
    'name_en',
    'description_id',
    'description_en',
    'sort_order',
    'default_duration_days',
])]
class ServiceType extends Model
{
    /** @use HasFactory<ServiceTypeFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Refuse an identity change before it reaches SQL.
     *
     * Each of the three is a boundary somebody could otherwise cross by
     * accident. Office is the security boundary, and M4 designs no Service Type
     * transfer (the D-089 reasoning). `code` is what other records classify
     * themselves by, so rewriting it re-points every reference at a handle that
     * no longer means what it did. `domain` decides which Matter surface may
     * offer the service at all, so flipping it reclassifies work already done.
     *
     * These are **engineering boundaries, not claims of legal impossibility**.
     * Lifting any of them needs its own decision, and this is where a future
     * reader finds that out.
     */
    protected static function booted(): void
    {
        static::updating(function (self $serviceType): void {
            foreach (['office_id', 'code', 'domain'] as $attribute) {
                if ($serviceType->isDirty($attribute)) {
                    throw new RuntimeException(
                        "service_types.{$attribute} is immutable (M4.1). "
                        .'Office, code, and domain are identity rather than content: other records '
                        .'classify themselves by them, so changing one silently redefines what they mean. '
                        .'Lifting this needs its own architecture decision.'
                    );
                }
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domain' => ServiceTypeDomain::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'default_duration_days' => 'integer',
        ];
    }
}
