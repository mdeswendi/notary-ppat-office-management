<?php

namespace App\Models;

use App\Domains\Billing\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** A single payment actually received from a client (D-139). */
#[Fillable(['amount', 'currency', 'received_on', 'method_code'])]
class ClientReceipt extends Model
{
    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $receipt): void {
            if ($receipt->isDirty('office_id')) {
                throw new RuntimeException('client_receipts.office_id is immutable (D-139).');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_on' => 'date',
            'method_code' => PaymentMethod::class,
        ];
    }

    /** @return BelongsTo<Party, $this> */
    public function clientParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'client_party_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
