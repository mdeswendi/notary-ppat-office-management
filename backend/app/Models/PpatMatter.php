<?php

namespace App\Models;

use Database\Factories\PpatMatterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * What is true of a Matter because it is a PPAT Matter (M7.1, D-121).
 *
 * The mirror of {@see NotaryMatter}, down to the reasoning. **An extension, not a
 * second Matter root**: `matters` is the one root and carries the canonical `domain`
 * discriminator (M4.2), so `matter_id` is the primary key and there is no surrogate.
 *
 * **No `HasUlids`.** The key is not generated here; it is the Matter.
 *
 * ## The three flags are stored and branch on nothing
 *
 * `03_DATABASE_ERD.md` line 770 recorded that M4 persisted nothing standing in for
 * these, because each is *"domain-semantic and unvalidated"*. M7 is the milestone the
 * ERD assigns them to, so M7 persists them — and **persisting a flag is not the same
 * act as branching on it**:
 *
 * - `registration_required` creates no register entry. `ppat_register_entries` is
 *   batch 11 and the register format is open question six (O-042). The same refusal
 *   M6 made twice for `notary_matters.requires_register_entry`.
 * - `tax_processing_required` triggers nothing. `ppat_tax_records` is not built at
 *   all — it has no canonical capability (O-040).
 * - `land_office_region` is free text. Which land office serves which region is
 *   administrative geography nobody here may encode.
 */
#[Fillable([
    'land_office_region',
    'tax_processing_required',
    'registration_required',
    'notes',
])]
class PpatMatter extends Model
{
    /** @use HasFactory<PpatMatterFactory> */
    use HasFactory;

    protected $primaryKey = 'matter_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::updating(function (self $extension): void {
            foreach (['matter_id', 'office_id'] as $attribute) {
                if ($extension->isDirty($attribute)) {
                    throw new RuntimeException(
                        "ppat_matters.{$attribute} is immutable (M7.1). "
                        .'It identifies the Matter this row extends, and the Office it inherits; '
                        .'moving either would silently reclassify a different Matter.'
                    );
                }
            }
        });
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    protected function casts(): array
    {
        return [
            'tax_processing_required' => 'boolean',
            'registration_required' => 'boolean',
        ];
    }
}
