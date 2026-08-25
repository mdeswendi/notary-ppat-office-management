<?php

namespace App\Http\Resources;

use App\Domains\Ppat\Enums\PpatWarkahStatus;
use App\Models\PpatWarkah;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Warkah bundle, as the list and the deed section return it (M7.4, D-121).
 *
 * ## `completeness_percentage` means one thing, and the payload says which
 *
 * **Every line this office listed has a file against it.** Not that the bundle is
 * legally sufficient — the mandatory Warkah composition per deed type is open question
 * three, and no requirement template drives the number (M7 lock section 8.2).
 *
 * `items_count` and `collected_count` travel beside it so the interface can render the
 * fraction the percentage came from rather than a bare figure. A reader who can see
 * *7 of 9 lines* understands what the number is counting; one who sees *78%* does not.
 *
 * **`status` is never derived from the percentage, in either direction.** `COMPLETE`
 * does not follow from 100% and 100% does not require `COMPLETE` — which of the two
 * governs sufficiency is what open question three does not answer.
 *
 * ## What has no field here, and why
 *
 * **No items array.** The lines are their own endpoint under the same capability, so a
 * list of forty bundles does not carry four hundred lines. The deed section asks for
 * them separately.
 *
 * **No Party identity of any kind.** A line may name a party; that stub lives on
 * {@see PpatWarkahItemResource} and carries a display name and nothing more (D-082).
 *
 * **`finalized_at` and `finalized_by` are exposed and always null.** Canonical columns
 * nothing writes: `ppat.warkah.finalize` is registered and unimplemented because its
 * trigger is open question eight (D-064, O-041). They are present so a later milestone
 * that does write them needs no payload change, and the interface renders them as
 * unset rather than hiding the concept.
 *
 * The `can_*` flags are **presentation hints computed from the real Policy**. They are
 * not an authorization surface: every endpoint authorizes again (D-113). There is no
 * `can_finalize` and no `can_archive`, because there is nothing behind either.
 *
 * @mixin PpatWarkah
 */
class PpatWarkahResource extends JsonResource
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(
        PpatWarkah $resource,
        private readonly array $capabilities = [],
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ppat_deed_id' => $this->ppat_deed_id,

            'status' => $this->status->value,

            // Arithmetic over the office's own checklist. See the class docblock.
            'completeness_percentage' => (int) $this->completeness_percentage,
            'items_count' => $this->whenCounted('items', fn (): int => (int) $this->items_count),

            'archive_location' => $this->archive_location,
            'notes' => $this->notes,

            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->userStub('verifier'),

            // Canonical, unwritten in M7 — see the class docblock.
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'finalized_by' => $this->userStub('finalizer'),

            'deed' => $this->whenLoaded('deed', fn (): array => [
                'id' => $this->deed->id,
                'deed_number' => $this->deed->deed_number,
                'title' => $this->deed->title,
                'status' => $this->deed->status->value,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            ...$this->capabilities,
        ];
    }

    /**
     * The statuses a caller may set, and the one that answers to `verify`.
     *
     * Exposed so the interface renders exactly the controls the API would accept.
     * `FINALIZED` and `ARCHIVED` appear in neither list, because nothing reaches them.
     *
     * @return array<string, array<int, string>>
     */
    public static function reachableStatuses(): array
    {
        return [
            'all' => array_map(
                static fn (PpatWarkahStatus $status): string => $status->value,
                PpatWarkahStatus::reachable(),
            ),
            'unreachable' => array_map(
                static fn (PpatWarkahStatus $status): string => $status->value,
                PpatWarkahStatus::unreachable(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userStub(string $relation): ?array
    {
        $user = $this->whenLoaded($relation);

        if (! $user instanceof User) {
            return null;
        }

        return ['id' => $user->id, 'name' => $user->name];
    }
}
