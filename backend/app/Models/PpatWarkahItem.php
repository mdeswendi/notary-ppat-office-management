<?php

namespace App\Models;

use Database\Factories\PpatWarkahItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RuntimeException;

/**
 * One line of a Warkah (M7.1, D-121).
 *
 * **`status` has no enum and no CHECK**, because the ERD gives this column no values.
 * `ppat_warkah.status` gets five; this one gets none, and the difference is not an
 * oversight to correct — an item-status vocabulary *is* the verification rule, and
 * *"what is the mandatory Warkah composition per deed type?"* is open question three.
 * The column is a nullable string nothing writes (O-041).
 *
 * **Completeness therefore counts documents, not statuses.** {@see hasDocument()} is
 * what `PpatWarkah::computeCompleteness()` asks, because a document being attached is
 * observable and needs no vocabulary.
 *
 * **`requirement_code` is stored and matched against nothing.** What it would match is
 * a requirement template, and D-104 keeps those unbuilt.
 *
 * **`title_id` and `title_en` are database fields, not UI strings** (`CLAUDE.md`
 * section 10) — the pattern `service_types` uses. A Warkah item title is content an
 * office writes.
 */
#[Fillable([
    'requirement_code',
    'title_id',
    'title_en',
    'sequence_no',
    'notes',
])]
class PpatWarkahItem extends Model
{
    /** @use HasFactory<PpatWarkahItemFactory> */
    use HasFactory;

    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            foreach (['office_id', 'warkah_id'] as $attribute) {
                if ($item->isDirty($attribute)) {
                    throw new RuntimeException(
                        "ppat_warkah_items.{$attribute} is immutable (M7.1, D-121). "
                        .'An item is a line of one bundle; moving it would silently re-file evidence '
                        .'against another transaction.'
                    );
                }
            }
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function warkah(): BelongsTo
    {
        return $this->belongsTo(PpatWarkah::class, 'warkah_id');
    }

    /**
     * The party this line concerns, if it concerns one.
     *
     * Nullable: an identity document belongs to a party, a land certificate belongs
     * to the transaction.
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * The Documents satisfying this line.
     *
     * **Reading this relation is not authorization.** Which of these a caller may open
     * answers to `documents.view` and its own Data Scope, and downloading answers to
     * `documents.download` — a Warkah capability is not a way to read what is filed
     * (D-115).
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'ppat_warkah_documents', 'warkah_item_id', 'document_id')
            ->withPivot(['attached_at', 'attached_by', 'office_id']);
    }

    /**
     * Has anything been collected for this line?
     *
     * The observable fact completeness counts, chosen precisely because it needs no
     * status vocabulary — see the class docblock.
     */
    public function hasDocument(): bool
    {
        return $this->documents()->exists();
    }

    protected function casts(): array
    {
        return [
            'sequence_no' => 'integer',
        ];
    }
}
