<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Party;
use App\Models\PpatWarkah;
use App\Models\PpatWarkahItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<PpatWarkahItem>
 */
class PpatWarkahItemFactory extends Factory
{
    /**
     * **`status` defaults to null and no state sets it.** The ERD gives
     * `ppat_warkah_items.status` no vocabulary — unlike `ppat_warkah.status`, which
     * gets five — and inventing `MISSING`/`VERIFIED` here would seed fixtures with a
     * vocabulary the product does not have (D-121, O-041).
     *
     * **`requirement_code` defaults to null.** It matches nothing: requirement
     * templates are unbuilt (D-104), so a seeded code would imply a catalogue lookup
     * that does not happen.
     *
     * `title_id` and `title_en` are bilingual **database** fields, so both are filled —
     * a row with one language is a row the interface renders blank in the other.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'warkah_id' => PpatWarkah::factory(),

            'office_id' => fn (array $attributes): string => PpatWarkah::query()
                ->findOrFail($attributes['warkah_id'])
                ->office_id,

            'requirement_code' => null,
            'title_id' => 'Berkas Uji '.$n,
            'title_en' => 'Test Document '.$n,

            'party_id' => null,
            'status' => null,
            'sequence_no' => 0,
            'notes' => null,
        ];
    }

    public function forWarkah(PpatWarkah $warkah): static
    {
        return $this->state(fn (array $attributes): array => [
            'warkah_id' => $warkah->getKey(),
            'office_id' => $warkah->office_id,
        ]);
    }

    public function party(Party $party): static
    {
        return $this->state(fn (array $attributes): array => [
            'party_id' => $party->getKey(),
        ]);
    }

    public function ordered(int $number): static
    {
        return $this->state(fn (array $attributes): array => [
            'sequence_no' => $number,
        ]);
    }

    /**
     * Attach a Document to this item after it is created.
     *
     * **This is what completeness counts** — a document being attached, not a status
     * (D-121). The pivot is written directly because M7.1 ships no Action for it;
     * M7.4 owns that surface.
     *
     * The Document and the attaching User are created in the item own Office, which
     * every composite key on the junction requires.
     */
    public function withDocument(?Document $document = null): static
    {
        return $this->afterCreating(function (PpatWarkahItem $item) use ($document): void {
            $file = $document ?? Document::factory()->create(['office_id' => $item->office_id]);

            $actor = User::factory()->create(['office_id' => $item->office_id]);

            DB::table('ppat_warkah_documents')->insert([
                'warkah_item_id' => $item->getKey(),
                'document_id' => $file->getKey(),
                'office_id' => $item->office_id,
                'attached_at' => now(),
                'attached_by' => $actor->getKey(),
            ]);
        });
    }
}
