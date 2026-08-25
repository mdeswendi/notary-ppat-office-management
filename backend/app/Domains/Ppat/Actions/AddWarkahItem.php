<?php

namespace App\Domains\Ppat\Actions;

use App\Models\Party;
use App\Models\PpatWarkah;
use App\Models\PpatWarkahItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Add a line to a Warkah (M7.4, D-121).
 *
 * ## The office writes its own checklist, because nobody has written one for it
 *
 * `requirement_code` is stored and **matched against nothing**. What it would match is
 * a requirement template, and D-104 keeps `service_document_requirements` and
 * `matter_requirements` unbuilt; `ppat_warkah_items.requirement_code` would be the
 * third place to invent a catalogue. The M7 lock section 8.2 is explicit: *"No
 * requirement template drives it."*
 *
 * So there is no "generate the standard items for this deed type" path, and building
 * one would answer open question three — *"what is the mandatory Warkah composition
 * per deed type?"* — which `CLAUDE.md` section 62 names among the things not to
 * invent. Every line here is one an office typed.
 *
 * **`title_id` and `title_en` are both required**, because they are bilingual
 * *database* fields rather than UI strings (`CLAUDE.md` section 10, the pattern
 * `service_types` uses). A line with one language filled in is a line that renders
 * blank for half the office.
 *
 * ## `status` is not written, and there is nothing to write
 *
 * The M7.4 brief specified `MISSING`, `RECEIVED`, `UNDER_REVIEW`, `VERIFIED`,
 * `REJECTED`, `NOT_APPLICABLE` and a default of `MISSING`. **`03_DATABASE_ERD.md`
 * gives this column no values at all** — which is why M7.1 built no
 * `PpatWarkahItemStatus` enum, left the column nullable with no default and no CHECK,
 * and left it out of the model's fillable set.
 *
 * An item-status vocabulary *is* the verification rule. It is also the reason
 * completeness counts documents: *"a document being attached is observable and needs
 * no vocabulary"* (O-041). The column stays canonical vocabulary nothing writes
 * (D-121 section 12).
 *
 * ## `sequence_no` orders the checklist
 *
 * Defaulted to the end of the list when the caller does not say. Ordering a checklist
 * is composition, not a legal rule.
 *
 * `party_id` is optional and structural: an identity document belongs to a party, a
 * land certificate belongs to the transaction. The controller resolves it through
 * canonical Party visibility first, so composing a Warkah never becomes a way to
 * discover which Parties exist.
 *
 * **Completeness is recomputed**, because the denominator just changed — a new line
 * with nothing against it lowers the percentage, which is the arithmetic being
 * honest rather than a penalty.
 */
class AddWarkahItem
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        User $actor,
        PpatWarkah $warkah,
        array $attributes,
        ?Party $party = null,
    ): PpatWarkahItem {
        return DB::transaction(function () use ($warkah, $attributes, $party): PpatWarkahItem {
            $item = new PpatWarkahItem;

            $item->fill($attributes);

            // Structural, and immutable afterwards: `PpatWarkahItem::booted()` refuses
            // a change to either, because moving a line would re-file evidence against
            // another transaction.
            $item->warkah_id = $warkah->getKey();
            $item->office_id = $warkah->office_id;

            $item->party_id = $party?->getKey();

            if (! array_key_exists('sequence_no', $attributes) || $attributes['sequence_no'] === null) {
                $item->sequence_no = ((int) $warkah->items()->max('sequence_no')) + 1;
            }

            $item->save();

            // The denominator moved. Only the percentage is written — never the
            // status, which is somebody's decision (lock section 8.2).
            $warkah->recalculateCompleteness();

            return $item;
        });
    }
}
