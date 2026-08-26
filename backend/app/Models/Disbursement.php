<?php

namespace App\Models;

use Database\Factories\DisbursementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * Money the office spent on a client's behalf (M8.2, D-124).
 *
 * **No `status`, and none may be added.** `disbursements.*` carries `view`,
 * `create` and `update` and no lifecycle verb at all, so a status column would be
 * vocabulary nothing could reach — the D-109 pattern this project records rather
 * than repeats.
 *
 * **A record, not a tax.** It says the office spent money for the client. It does
 * not know whether that money was a tax, a fee or a courier charge, it computes
 * no rate, and it gates nothing. That is the line that keeps O-040 intact:
 * `ppat_tax_records` remains unbuilt and this is not a back door to it.
 *
 * `invoice_id` is nullable and **nothing copies the amount onto that invoice** —
 * re-billing a cost is adding a line under `invoices.update`, a deliberate act.
 * A total that moved because a cost was recorded elsewhere would change an
 * invoice nobody edited.
 */
#[Fillable([
    'description',
    'amount',
    'currency',
    'incurred_on',
    'reference',
    'notes',
])]
class Disbursement extends Model
{
    /** @use HasFactory<DisbursementFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (self $disbursement): void {
            if ($disbursement->isDirty('office_id')) {
                throw new RuntimeException(
                    'disbursements.office_id is immutable (M8.2). Office is the security boundary and '
                    .'the OFFICE scope predicate. Disbursement id: '
                    .($disbursement->getKey() ?? 'unsaved')
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'incurred_on' => 'date',
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
}
