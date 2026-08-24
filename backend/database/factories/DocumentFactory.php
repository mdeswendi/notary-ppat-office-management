<?php

namespace Database\Factories;

use App\Domains\Document\AllocateDocumentReference;
use App\Domains\Document\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * `office_id`, `status`, `current_version_id`, `created_by` and the archival
     * pair are set here rather than through `fill()`, because none of them is
     * fillable on the model: Office is identity, status and archival answer to
     * their own capability, and attribution belongs to the application.
     *
     * **`created_by` creates a User rather than defaulting to null**, unlike
     * `ProjectFactory` and `MatterFactory`. The column is `NOT NULL` here —
     * a filed document always has a filer — so a null default would make the
     * factory unusable rather than neutral. Tests that care *which* user filed it
     * state so through {@see createdBy()}.
     *
     * **`document_number` is allocated through the real allocator** rather than
     * faked, because the column became `NOT NULL` at M5.2 (D-117) and a made-up
     * value would let a test pass against a reference the product could never
     * produce — and would quietly become a second numbering path beside the one
     * M5.1 built. The closure runs after `office_id` resolves, so the allocation
     * lands in the right namespace. This changed at M5.2: while the column was
     * nullable the default was null, and {@see numbered()} was how a test opted in.
     *
     * **`is_sensitive` defaults to false and is never inferred** from
     * `document_type_code` (D-115). A factory that guessed would make every
     * sensitive-capability test pass or fail for reasons the test never stated.
     *
     * **`status` is `DRAFT` and only `DRAFT` is reachable in M5.1.** The other six
     * canonical values exist in the enum; no transition rule does, so nothing here
     * moves a document into one.
     *
     * Values are deliberately non-legal: `Dokumen Uji` is obviously a fixture, so
     * no test datum can be mistaken for a real office document. **No NIK, NPWP or
     * other Party identity ever appears here** — a Document record must not carry
     * sensitive identity even in a test.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),

            // Nullable in the closure's own return type on purpose: a test that
            // deliberately passes a null Office must reach the database and be
            // refused there, not die in the factory with a TypeError.
            'document_number' => fn (array $attributes): ?string => ($attributes['office_id'] ?? null) === null
                ? null
                : app(AllocateDocumentReference::class)->forOffice((string) $attributes['office_id']),

            'document_type_code' => null,
            'title' => 'Dokumen Uji '.fake()->unique()->numberBetween(1, 999999),
            'status' => DocumentStatus::DRAFT,
            'is_sensitive' => false,
            'document_date' => null,
            'expiry_date' => null,
            'notes' => null,
            'current_version_id' => null,

            // A User in the **same Office**, so the OWN and OFFICE predicates
            // agree by construction. A filer from another Office would be a
            // fixture no upload path could produce, and would make an OFFICE test
            // pass through the OWN branch by accident.
            'created_by' => fn (array $attributes): ?string => $attributes['office_id'] === null
                ? null
                : User::factory()->for(Office::query()->findOrFail($attributes['office_id']))->create()->getKey(),

            'updated_by' => null,
            'archived_at' => null,
            'archived_by' => null,
        ];
    }

    /**
     * File the Document in a particular Office.
     *
     * Also moves the default filer into that Office, so `created_by` does not
     * silently stay behind in the Office the definition generated.
     */
    public function inOffice(Office|string $office): static
    {
        $officeId = $office instanceof Office ? $office->getKey() : $office;

        return $this->state(fn (array $attributes): array => [
            'office_id' => $officeId,
            'created_by' => fn (): string => User::factory()
                ->for(Office::query()->findOrFail($officeId))
                ->create()
                ->getKey(),
        ]);
    }

    /**
     * Name the person who filed it.
     *
     * **Does not move the Document into that user's Office**: a test asserting
     * that `OWN` reaches across Offices while `OFFICE` does not needs the two to
     * be able to disagree.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'created_by' => $user->getKey(),
        ]);
    }

    /**
     * A document carrying identity or tax material (D-115).
     *
     * The flag alone — no NIK, no NPWP, no scan. What makes a fixture sensitive
     * here is the classification the filer chose, which is the only thing the
     * column records.
     */
    public function sensitive(bool $sensitive = true): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_sensitive' => $sensitive,
        ]);
    }

    public function status(DocumentStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    public function type(string $documentTypeCode): static
    {
        return $this->state(fn (array $attributes): array => [
            'document_type_code' => $documentTypeCode,
        ]);
    }

    /**
     * Allocate the reference in a **particular year**.
     *
     * The definition already allocates one for the current year, so this exists
     * only for tests that need to place a document in a specific Office-year
     * namespace — reference formatting, year rollover, and per-Office uniqueness.
     */
    public function numbered(?int $year = null): static
    {
        return $this->state(fn (array $attributes): array => [
            // Nullable in the closure's own return type on purpose: a test that
            // deliberately passes a null Office must reach the database and be
            // refused there, not die in the factory with a TypeError.
            'document_number' => fn (array $resolved): ?string => ($resolved['office_id'] ?? null) === null
                ? null
                : app(AllocateDocumentReference::class)->forOffice((string) $resolved['office_id'], $year),
        ]);
    }

    /**
     * Archived: a state, never a deletion (`CLAUDE.md` section 30).
     */
    public function archived(?User $by = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DocumentStatus::ARCHIVED,
            'archived_at' => now(),
            'archived_by' => $by?->getKey(),
        ]);
    }
}
