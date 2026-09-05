<?php

namespace Database\Factories;

use App\Domains\Document\DocumentStorage;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    /**
     * **Writes no file.** This builds the metadata row only; tests that care
     * about bytes on disk go through {@see DocumentStorage} with a faked disk,
     * which is the path production takes. A factory that wrote real files would
     * leave them behind after the suite and would make every version test depend
     * on the filesystem.
     *
     * `storage_path` follows the shape `DocumentStorage` produces —
     * `documents/{office}/{YYYY}/{MM}/{ulid}.pdf` — so a fixture cannot drift
     * into a layout the service would never generate. It contains neither
     * `public/` nor `uploads/`; the model refuses either on both engines.
     *
     * `checksum_sha256` is a real SHA-256 of random bytes rather than a
     * placeholder string. `str_repeat('a', 64)` would satisfy the format and
     * would make every fixture collide, so a test asserting two versions differ
     * would pass or fail for the wrong reason.
     *
     * `uploaded_by` defaults to a User in the parent Document's Office, so the
     * fixture matches what an upload path could actually produce.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),

            // First version by default. Callers stacking versions state the
            // number explicitly; `UNIQUE (document_id, version_number)` refuses a
            // duplicate, which is the point.
            'version_number' => 1,

            'storage_disk' => 'local',

            'storage_path' => fn (array $attributes): string => sprintf(
                '%s/%s/%s/%s/%s.pdf',
                DocumentStorage::ROOT,
                ($attributes['document_id'] === null
                    ? 'unknown'
                    : (string) Document::query()->whereKey($attributes['document_id'])->value('office_id')),
                now()->format('Y'),
                now()->format('m'),
                Str::ulid(),
            ),

            // Deliberately non-legal, and deliberately not a person's name: a
            // real upload's original filename is often the subject's own, which
            // is exactly what must never appear in a fixture.
            'original_filename' => 'dokumen-uji.pdf',
            'stored_filename' => Str::ulid().'.pdf',

            'mime_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(1024, 4_194_304),
            'checksum_sha256' => hash('sha256', Str::ulid().fake()->uuid()),

            'uploaded_by' => fn (array $attributes): ?string => $attributes['document_id'] === null
                ? null
                : User::factory()
                    ->for(Office::query()->findOrFail(
                        Document::query()->whereKey($attributes['document_id'])->value('office_id')
                    ))
                    ->create()
                    ->getKey(),

            'uploaded_at' => now(),
        ];
    }

    public function forDocument(Document $document): static
    {
        return $this->state(fn (array $attributes): array => [
            'document_id' => $document->getKey(),
        ]);
    }

    public function versionNumber(int $versionNumber): static
    {
        return $this->state(fn (array $attributes): array => [
            'version_number' => $versionNumber,
        ]);
    }

    public function uploadedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'uploaded_by' => $user->getKey(),
        ]);
    }
}
