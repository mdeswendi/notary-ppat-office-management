<?php

namespace Database\Factories;

use App\Domains\MasterData\Enums\ServiceTypeDomain;
use App\Domains\Matter\AllocateMatterReference;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\Enums\MatterStatus;
use App\Domains\Project\Enums\ProjectPriority;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Project;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Matter>
 */
class MatterFactory extends Factory
{
    /**
     * Creates a parent Project only when the caller has not supplied one, and
     * **takes the Office from that Project** — the D-099 inheritance rule, which
     * the composite foreign key would refuse to let the factory break anyway.
     *
     * `office_id`, `project_id`, `domain`, `status`, `pic_user_id`, and
     * `created_by` are set here rather than through `fill()`, because none of them
     * is fillable on the model: three are identity and three answer to their own
     * capability or to the application.
     *
     * `pic_user_id` and `created_by` default to **null**, matching
     * `ProjectFactory`. M4.2 has no create Action to set them, and a factory that
     * invented a PIC would quietly make every `ASSIGNED` test pass for the wrong
     * reason. Tests that care state who created or was assigned, explicitly.
     *
     * `service_type_id` defaults to **null**, which is a complete Matter rather
     * than a draft (D-102) — no validated service catalogue exists. The
     * {@see withServiceType()} state creates one in the Matter's own Office and
     * domain, because the composite foreign key enforces both.
     *
     * Values are deliberately non-legal: `Pekerjaan Uji` is obviously a fixture,
     * so no test datum can be mistaken for real office work.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $project = Project::factory();

        return [
            'project_id' => $project,

            // Inherited from the parent Project rather than generated. The
            // closure runs after `project_id` resolves, so a caller supplying
            // their own Project still gets that Project's Office.
            //
            // Nullable on purpose: a test that deliberately passes a null or
            // nonexistent `project_id` must reach the database and be refused
            // there, not die in the factory with a TypeError.
            'office_id' => fn (array $attributes): ?string => $attributes['project_id'] === null
                ? null
                : Project::query()->whereKey($attributes['project_id'])->value('office_id'),

            'service_type_id' => null,
            'domain' => MatterDomain::NOTARY,

            // Allocated through the real allocator rather than faked, because
            // `matter_number` became NOT NULL at M4.4 and a made-up value would
            // let a test pass against a reference the product could never
            // produce — and would quietly become a second numbering path beside
            // the one D-103 locked. The closure runs after `office_id` and
            // `domain` resolve, so the allocation lands in the right namespace.
            // Nullable in the closure's own return type on purpose: a test that
            // deliberately passes a null Office or domain must reach the database
            // and be refused there, not die in the factory.
            'matter_number' => function (array $attributes): ?string {
                $officeId = $attributes['office_id'] ?? null;
                $domain = $attributes['domain'] ?? null;

                if ($officeId === null || $domain === null) {
                    return null;
                }

                return app(AllocateMatterReference::class)->forOffice(
                    (string) $officeId,
                    $domain instanceof MatterDomain ? $domain : MatterDomain::from((string) $domain),
                );
            },

            'title' => 'Pekerjaan Uji '.fake()->unique()->numberBetween(1, 999999),
            'status' => MatterStatus::OPEN,
            'priority' => ProjectPriority::NORMAL,
            'pic_user_id' => null,
            'opened_at' => null,
            'target_completion_date' => null,
            'completed_at' => null,
            'notes' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function domain(MatterDomain $domain): static
    {
        return $this->state(fn (array $attributes): array => [
            'domain' => $domain,
        ]);
    }

    public function status(MatterStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'created_by' => $user->getKey(),
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'pic_user_id' => $user->getKey(),
        ]);
    }

    /**
     * Classify the Matter with a Service Type created in its **own Office and
     * domain**, which is the only combination the composite foreign key accepts.
     *
     * The domain is a parameter rather than read from the pending attributes,
     * because a factory state cannot rely on `domain` having been resolved yet —
     * attribute closures are expanded in declaration order, and `service_type_id`
     * is declared before `domain`. Setting both here keeps the pair consistent by
     * construction rather than by luck.
     */
    public function withServiceType(MatterDomain $domain = MatterDomain::NOTARY): static
    {
        return $this->state(fn (array $attributes): array => [
            'domain' => $domain,

            // `office_id` is declared before this key and is therefore already
            // resolved to the parent Project's Office when this closure runs.
            'service_type_id' => fn (array $resolved): string => ServiceType::factory()
                ->for(Office::query()->findOrFail($resolved['office_id']))
                ->domain(ServiceTypeDomain::from($domain->value))
                ->create()
                ->getKey(),
        ]);
    }
}
