<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\NotaryDeed;
use App\Models\NotaryMinuta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotaryMinuta>
 */
class NotaryMinutaFactory extends Factory
{
    /**
     * **The deed is created first and everything follows it.** A Minuta's Office is
     * its deed's, and its Document must live in that Office too — the composite keys
     * accept nothing else, so generating them independently would produce a fixture
     * the database refuses.
     *
     * **`release_status`, `archived_at` and `archived_by` default to null and no
     * state sets them.** The ERD gives `release_status` no vocabulary, and inventing
     * one in a factory would put a value into fixtures that no code path can produce
     * — which is how a test comes to assert behaviour the product does not have.
     *
     * The shelf fields default to null: an office that files digitally has no volume
     * or bundle, and a factory that invented one would make every payload look as
     * though a physical original existed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notary_deed_id' => NotaryDeed::factory(),

            'office_id' => fn (array $attributes): string => NotaryDeed::query()
                ->findOrFail($attributes['notary_deed_id'])
                ->office_id,

            // A Document in the deed's own Office, which the composite key requires.
            'document_id' => fn (array $attributes): string => Document::factory()
                ->create(['office_id' => $attributes['office_id']])
                ->getKey(),

            'archive_location' => null,
            'volume_number' => null,
            'bundle_number' => null,
            'notes' => null,

            'release_status' => null,
            'archived_at' => null,
            'archived_by' => null,
        ];
    }

    /**
     * File it against a particular deed, moving the Office with it.
     */
    public function forDeed(NotaryDeed $deed): static
    {
        return $this->state(fn (array $attributes): array => [
            'notary_deed_id' => $deed->getKey(),
            'office_id' => $deed->office_id,
            'document_id' => fn (): string => Document::factory()
                ->create(['office_id' => $deed->office_id])
                ->getKey(),
        ]);
    }

    public function document(Document $document): static
    {
        return $this->state(fn (array $attributes): array => [
            'document_id' => $document->getKey(),
        ]);
    }

    /**
     * Where the physical original sits.
     */
    public function shelved(string $location, string $volume, string $bundle): static
    {
        return $this->state(fn (array $attributes): array => [
            'archive_location' => $location,
            'volume_number' => $volume,
            'bundle_number' => $bundle,
        ]);
    }
}
