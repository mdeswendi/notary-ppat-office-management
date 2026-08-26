<?php

namespace App\Models;

use App\Domains\Billing\Enums\QuotationStatus;
use Database\Factories\QuotationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A priced offer to a client (M8.2, D-124).
 *
 * Two states — `DRAFT` and `APPROVED` — because `quotations.approve` is the only
 * lifecycle verb the catalogue gives. {@see QuotationStatus} records why the
 * brief's six became two, and why a quotation that comes to nothing stays
 * `DRAFT` rather than being marked rejected or expired.
 *
 * **Converting is a property of the invoice, not of this record.** There is no
 * `quotations.convert` code; an invoice created from a quotation carries
 * `quotation_id`, which is why `invoices()` here is a `hasMany` rather than a
 * status this row would have to be told about.
 */
#[Fillable([
    'title',
    'description',
    'currency',
    'valid_until',
    'notes',
])]
class Quotation extends Model
{
    /** @use HasFactory<QuotationFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (self $quotation): void {
            foreach (['office_id', 'quotation_number'] as $column) {
                if ($quotation->isDirty($column)) {
                    throw new RuntimeException(
                        "quotations.{$column} is immutable (M8.2). Quotation id: "
                        .($quotation->getKey() ?? 'unsaved')
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'subtotal_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'valid_until' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function clientParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'client_party_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @return HasMany<QuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('line_number');
    }

    /**
     * Invoices raised from this offer.
     *
     * Plural on purpose: an office may bill an agreed quotation in stages, and
     * nothing in the catalogue says a quotation converts exactly once.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
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
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
