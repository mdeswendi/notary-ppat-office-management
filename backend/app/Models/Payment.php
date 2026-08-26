<?php

namespace App\Models;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Money received against an invoice (M8.2, D-124, D-125, O-050).
 *
 * ## Nothing here can be corrected, and the model says so
 *
 * The catalogue gives payments `view`, `create` and `verify` — **no `update`, no
 * `delete`, no `reject`**. So there is no soft delete, no `updated_by`, and
 * `booted()` refuses to change the two things that would matter: the amount and
 * the invoice it settles.
 *
 * That guard is not a substitute for the missing capability; it is what keeps a
 * gap from being quietly filled by a `save()` somewhere. A payment recorded
 * wrongly and **verified** has no remedy in this product (O-050), which M8.2
 * ships honestly rather than closing with an uncatalogued verb.
 *
 * **The verify gate is the whole control.** Only `VERIFIED` payments count
 * toward an invoice's paid total, so a mistake caught before verification moves
 * no figure anywhere and stays visible rather than hidden.
 *
 * ## `paid_at` is not `created_at`
 *
 * `paid_at` is when the office says the money moved; `created_at` is when
 * somebody typed it. A transfer noticed on Monday may have landed on Friday, and
 * conflating the two would make a late entry look like a late payment.
 */
#[Fillable([
    'reference',
    'notes',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $payment): void {
            foreach (['office_id', 'invoice_id', 'amount', 'currency', 'paid_at'] as $column) {
                if ($payment->isDirty($column)) {
                    throw new RuntimeException(
                        "payments.{$column} is immutable (M8.2, O-050). The catalogue gives payments no "
                        .'update, delete or reject capability, so a recorded payment is corrected by '
                        .'recording the truth alongside it, never by rewriting it. Payment id: '
                        .($payment->getKey() ?? 'unsaved')
                    );
                }
            }
        });

        static::deleting(function (self $payment): void {
            throw new RuntimeException(
                'payments has no delete capability in the canonical catalogue (M8.2, O-050). '
                .'Payment id: '.($payment->getKey() ?? 'unsaved')
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'method_code' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::VERIFIED->value);
    }
}
