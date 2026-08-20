<?php

namespace App\Domains\Party;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Party\Enums\PartyType;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Party half of participation, wherever participation happens.
 *
 * **Participation authority and Party visibility are two separate questions**,
 * and this class exists so the second one is never skipped. A participation
 * Policy — `ProjectPartyPolicy`, `MatterPartyPolicy` — answers "may this actor
 * work on this record's participation" against the parent record. The
 * references are deliberately plain text rather than `@see` tags: Pint's
 * `fully_qualified_strict_types` turns a tagged reference into a real `use`
 * statement, and a Party-domain class importing two Policies to satisfy a
 * comment is a dependency nothing asked for. That says nothing whatever
 * about which Parties the actor may see, and treating the first as an answer to
 * the second would make `*.parties.manage` a back door into the Party directory:
 * a capability granted for organising work quietly becoming one for discovering
 * people.
 *
 * **The two subtype branches stay independent.** An Individual candidate is
 * reachable under `parties.view`; a Company candidate under `companies.view`.
 * The Party domain has evaluated them separately since M2 (D-028: grants union
 * across roles, they do not merge into one another), and an actor may genuinely
 * hold one and not the other. Collapsing them into a single "can see Parties"
 * test would silently widen whichever branch the actor lacks.
 *
 * ## Why this is one class rather than one per parent domain
 *
 * It was `ProjectParticipantVisibility` at M3.4, and M4.5 generalized it rather
 * than copying it (D-110). Every question it answers is keyed on an **Office
 * id** and a Party subtype; the parent record contributed nothing but
 * `office_id`. Two copies of a security check drift, and the drift is silent —
 * one domain gains a fix the other does not, and nothing fails to announce it.
 * D-105 keeps `matter_parties` independent of `project_parties` as *data*, which
 * is a statement about tables and rows; it is not an instruction to re-implement
 * the Party permission rule twice.
 *
 * Note what this class does **not** decide: whether a Party already linked stays
 * listed. It does not — a participation the office recorded is the parent
 * record's data, and it remains visible as a minimal stub with
 * `can_view_party = false`. Withdrawing the row would misreport the record's
 * composition to somebody authorized to read it, which is worse than declining
 * to link onward.
 */
class ParticipantVisibility
{
    /**
     * Which Party-domain permission governs each subtype.
     */
    private const PERMISSION_FOR_TYPE = [
        PartyType::INDIVIDUAL->value => 'parties.view',
        PartyType::COMPANY->value => 'companies.view',
    ];

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PartyVisibility $visibility,
    ) {}

    /**
     * Which of these Parties may the actor open in the Party surfaces?
     *
     * Two queries at most, never one per row: the actor's effective access does
     * not vary by row, so it is resolved once per branch and the record
     * predicate asked in bulk. That is the N+1 M2.6 measured and fixed on the
     * Party reverse view, and a participation list has exactly the same shape.
     *
     * Archived Parties resolve to `false` here because `Party::query()` excludes
     * soft-deleted rows — which is correct: the Party detail page would refuse
     * them too, so advertising a link the interface cannot honour would be a
     * lie the badge tells.
     *
     * @param  array<int, Party>  $parties
     * @return array<string, true> keyed by Party id
     */
    public function viewableKeys(array $parties, User $actor): array
    {
        $byType = [];

        foreach ($parties as $party) {
            $byType[$party->party_type->value][] = $party->getKey();
        }

        $viewable = [];

        foreach ($byType as $type => $partyIds) {
            $access = $this->resolver->resolve($actor, self::PERMISSION_FOR_TYPE[$type]);

            $viewable += $this->visibility->reachablePartyKeys($partyIds, $actor, $access);
        }

        return $viewable;
    }

    /**
     * Candidate Parties this actor may link inside this Office.
     *
     * Three conditions apply together, and each is load-bearing:
     *
     *   1. the Party is in the **parent record's** Office — a cross-office
     *      participation is unrepresentable in the database anyway (D-098,
     *      D-105), and offering one would be an existence oracle for another
     *      Office's directory;
     *   2. the Party is **not archived** — a retired record should not be picked
     *      for new work, though one already linked stays listed (D-081);
     *   3. the actor **reaches that Office under the subtype's own permission**.
     *
     * Condition 3 is why `ALL` does not help here in the way a reader might
     * expect. `ALL` on the Party side lets an actor *see* another Office's
     * Parties, but condition 1 has already fixed the Office to the parent
     * record's, so the only thing `ALL` buys is reaching a parent record in an
     * Office that is not the actor's own. It never bridges the two Offices.
     *
     * A branch the actor cannot reach contributes nothing rather than
     * everything — the query starts closed and each branch opens only itself.
     *
     * @return Builder<Party>
     */
    public function candidateQuery(User $actor, string $officeId): Builder
    {
        $allowedTypes = [];

        foreach (self::PERMISSION_FOR_TYPE as $type => $permission) {
            $access = $this->resolver->resolve($actor, $permission);

            if ($this->visibility->reachesOffice($actor, $access, $officeId)) {
                $allowedTypes[] = $type;
            }
        }

        $query = Party::query()->where('office_id', $officeId);

        // Fail closed. An actor holding neither Party-domain permission gets an
        // empty candidate list, not the whole Office.
        if ($allowedTypes === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('party_type', $allowedTypes);
    }

    /**
     * Re-resolve a submitted Party id through the authorized candidate query.
     *
     * **The submitted id is never trusted**, which is the point of this method
     * existing rather than the Action reading `Party::find()`. An id obtained
     * elsewhere — guessed, remembered from another Office, copied from a record
     * the actor may not open — must not become a participation merely because
     * the actor may manage this record's participants.
     *
     * Returns `null` for every failure mode without distinguishing them. "No
     * such Party", "another Office", "archived", and "a subtype you cannot see"
     * are one answer, because telling them apart would answer a question the
     * caller has no permission to ask.
     */
    public function resolveCandidate(User $actor, string $officeId, string $partyId): ?Party
    {
        return $this->candidateQuery($actor, $officeId)->whereKey($partyId)->first();
    }
}
