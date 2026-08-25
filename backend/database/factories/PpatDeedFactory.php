<?php

namespace Database\Factories;

use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Ppat\Enums\PpatDeedStatus;
use App\Models\Matter;
use App\Models\Office;
use App\Models\PpatDeed;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PpatDeed>
 */
class PpatDeedFactory extends Factory
{
    /**
     * `office_id`, `matter_id`, `status`, `deed_number` and the three act-pairs are
     * set here rather than through `fill()`, because none is fillable: Office and
     * Matter are identity, status and the number answer to their own capabilities,
     * and the pairs are attribution the application decides.
     *
     * **The Matter is created first and the Office follows it.** A deed's Office is
     * its Matter's — the composite key `(matter_id, office_id)` accepts no other —
     * so generating the two independently would produce a fixture the database
     * refuses. This is the shape `TaskFactory::forMatter()` uses, promoted to the
     * definition because a deed without a Matter is not a thing.
     *
     * **A `PPAT` Matter**, because the Policy refuses a Notary one and a factory that
     * produced the wrong domain would make every `create` test fail for a reason
     * unrelated to what it was testing.
     *
     * **`deed_number` defaults to null**, which is a complete deed rather than a
     * draft: no creation path allocates one, the office supplies it when it knows it,
     * and a factory that invented a number would quietly make uniqueness tests pass
     * for the wrong reason. `numbered()` says what, explicitly.
     *
     * **`deed_date` defaults to null** for the same reason as `TaskFactory::due_at`:
     * a default date would make fixtures behave differently depending on when the
     * suite ran.
     *
     * Values are deliberately non-legal: `Akta PPAT Uji` is obviously a fixture, and no
     * real deed type code appears anywhere in this file.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $matter = Matter::factory()->state(['domain' => MatterDomain::PPAT]);

        return [
            'matter_id' => $matter,

            // Follows the Matter, which the composite key requires.
            'office_id' => fn (array $attributes): string => Matter::query()
                ->findOrFail($attributes['matter_id'])
                ->office_id,

            'deed_number' => null,
            'deed_date' => null,
            'deed_type_code' => null,

            'title' => 'Akta PPAT Uji '.fake()->unique()->numberBetween(1, 999999),
            'status' => PpatDeedStatus::DRAFT,

            'final_document_id' => null,

            'reviewed_at' => null,
            'reviewed_by' => null,
            'approved_at' => null,
            'approved_by' => null,
            'finalized_at' => null,
            'finalized_by' => null,
            'locked_at' => null,
        ];
    }

    /**
     * Record the deed against a particular Matter.
     *
     * **Moves the Office with it**, because the composite key permits nothing else —
     * the same reason `TaskFactory::createdBy()` moves the Office and for the same
     * structural cause.
     */
    public function forMatter(Matter $matter): static
    {
        return $this->state(fn (array $attributes): array => [
            'matter_id' => $matter->getKey(),
            'office_id' => $matter->office_id,
        ]);
    }

    public function status(PpatDeedStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    /**
     * Under review, with the pair written together.
     *
     * A PostgreSQL CHECK and a model guard both refuse half of an act, so a state
     * that sets one of these statuses must set both columns. The actor defaults to a
     * User in the deed's own Office, which the composite key requires.
     */
    public function reviewed(?User $by = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PpatDeedStatus::UNDER_REVIEW,
            'reviewed_at' => now(),

            // **Lazy, and it has to be.** The actor must live in the deed's Office,
            // which is itself resolved from the Matter — so an eager call would read
            // an `office_id` that is still an unresolved closure. The
            // `TaskFactory::completed()` shape, for the same reason.
            'reviewed_by' => fn (array $resolved): string => $by?->getKey()
                ?? $this->actorIn($resolved),
        ]);
    }

    public function approved(?User $by = null): static
    {
        return $this->reviewed($by)->state(fn (array $attributes): array => [
            'status' => PpatDeedStatus::APPROVED,
            'approved_at' => now(),
            'approved_by' => fn (array $resolved): string => $by?->getKey()
                ?? $this->actorIn($resolved),
        ]);
    }

    /**
     * Finalized — and therefore read-only (`CLAUDE.md` sections 29 and 64).
     *
     * Builds on `approved()` so the earlier pairs are present too: a deed that was
     * finalized without ever having been approved is a row no code path produces.
     */
    public function finalized(?User $by = null): static
    {
        return $this->approved($by)->state(fn (array $attributes): array => [
            'status' => PpatDeedStatus::FINALIZED,
            'finalized_at' => now(),
            'finalized_by' => fn (array $resolved): string => $by?->getKey()
                ?? $this->actorIn($resolved),
        ]);
    }

    /**
     * Give the deed its legal number.
     *
     * The caller supplies it. **No format is generated**, because generating one
     * would be inventing the numbering rule this milestone refuses to invent
     * (D-120) — and a fixture that looked like a real deed number would teach a
     * reader the wrong thing.
     */
    public function numbered(string $number): static
    {
        return $this->state(fn (array $attributes): array => [
            'deed_number' => $number,
        ]);
    }

    /**
     * A User in the deed's own Office, which every composite user key requires.
     *
     * Called only from a lazily-resolved attribute, so `office_id` is a real value
     * here rather than the closure the definition starts with.
     *
     * @param  array<string, mixed>  $resolved
     */
    private function actorIn(array $resolved): string
    {
        return User::factory()
            ->for(Office::query()->findOrFail($resolved['office_id']))
            ->create()
            ->getKey();
    }
}
