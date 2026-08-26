<?php

namespace App\Models;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\PaymentStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * A demand for payment (M8.2, D-124).
 *
 * ## Settlement is computed, never stored
 *
 * `paid_amount`, `outstanding_amount`, `isSettled()` and `isOverdue()` are all
 * derived. The brief specified stored `paid_amount` and `remaining_amount`
 * columns and a status vocabulary carrying `PARTIALLY_PAID`, `PAID` and
 * `OVERDUE`; none of that is here.
 *
 * **Stored, they would drift.** Every one is a function of rows that change
 * elsewhere — verified payments, and the passage of time. `OVERDUE` in
 * particular would need a scheduled job and would be wrong every night until it
 * ran. M5.4 settled the same question for Task: *"`isOverdue()` is computed,
 * never stored. A row that went stale overnight would need a job to notice; a
 * comparison at read time is always right."*
 *
 * **Nothing authorizes setting them either.** Recording a payment answers to
 * `payments.create` and `payments.verify`, neither of which is a verb on the
 * invoice — and after `issue` this row is finalized, because `invoices.update`
 * is a `DRAFT`-only act.
 *
 * `scopeWithSettlement()` supplies the aggregate in one query, so a page of
 * fifty invoices costs one, not fifty.
 *
 * ## `office_id` and `invoice_number` are immutable
 *
 * `office_id` is the security boundary and the `OFFICE` scope predicate; moving
 * an invoice between Offices would silently redefine who may see it and would
 * strand every composite key pointing at it. `invoice_number` belongs to the
 * record that received it — the rule `Property::booted()` and the Project
 * allocator already hold (D-103).
 */
#[Fillable([
    'title',
    'description',
    'currency',
    'due_date',
    'notes',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            foreach (['office_id', 'invoice_number'] as $column) {
                if ($invoice->isDirty($column)) {
                    throw new RuntimeException(
                        "invoices.{$column} is immutable (M8.2). Office is the security boundary and "
                        .'the OFFICE scope predicate; the reference belongs to the record that received '
                        .'it (D-103). Invoice id: '.($invoice->getKey() ?? 'unsaved')
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
            'status' => InvoiceStatus::class,
            'subtotal_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'due_date' => 'date',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
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
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('line_number');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('paid_at');
    }

    /**
     * @return HasMany<Disbursement, $this>
     */
    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class);
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
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Attach the verified-payment total in one query.
     *
     * **Only `VERIFIED` payments count** (D-125, O-050). A pending payment is
     * visible on the invoice and contributes to nothing, which is what makes the
     * verify gate the control it is: a mis-entered payment caught before
     * verification moves no figure anywhere.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWithSettlement(Builder $query): Builder
    {
        return $query->withSum(
            ['payments as verified_payments_sum' => fn (Builder $payments): Builder => $payments
                ->where('status', PaymentStatus::VERIFIED->value)],
            'amount',
        );
    }

    /**
     * What has actually been paid.
     *
     * Falls back to a query when the aggregate was not loaded, so a single
     * hydrated invoice is still correct — but every list path uses
     * {@see self::scopeWithSettlement()} and pays one query for the page.
     */
    public function paidAmount(): string
    {
        $sum = $this->getAttribute('verified_payments_sum')
            ?? $this->payments()->where('status', PaymentStatus::VERIFIED->value)->sum('amount');

        return number_format((float) $sum, 2, '.', '');
    }

    public function outstandingAmount(): string
    {
        // A cancelled invoice asks for nothing, whatever its total says.
        if (! $this->status->isCollectable()) {
            return '0.00';
        }

        $outstanding = (float) $this->total_amount - (float) $this->paidAmount();

        return number_format(max(0, $outstanding), 2, '.', '');
    }

    public function isSettled(): bool
    {
        return $this->status->isCollectable() && (float) $this->outstandingAmount() <= 0.0;
    }

    /**
     * Past its due date and still owed something.
     *
     * Computed at read time, never stored — see the class docblock. A draft is
     * never overdue, because nobody has been asked to pay it yet.
     */
    public function isOverdue(): bool
    {
        if (! $this->status->isCollectable() || $this->due_date === null) {
            return false;
        }

        return $this->due_date->isBefore(Date::now()->startOfDay()) && ! $this->isSettled();
    }
}
