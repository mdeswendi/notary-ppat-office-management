<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Party\PartyVisibility;
use App\Http\Controllers\Controller;
use App\Http\Resources\PartyDirectoryResource;
use App\Models\Party;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The unified Party Directory — one place to find a person or an organization
 * (M2.5).
 *
 * **Read-only, and the only generic Party surface that will exist.** There is no
 * `POST`, `PATCH`, or `DELETE` here and there must never be: Individual and
 * Company own their own lifecycles, each with its own permissions, validation,
 * and aggregate rules. A generic Party mutation endpoint would be a second way
 * to write the same records with none of that (D-078). This aggregates
 * discovery, nothing more.
 *
 * **No new permission.** Visibility is composed from the two subtype
 * capabilities the caller already holds, and inventing a
 * `parties.directory.view` would mean an administrator could grant the directory
 * without granting sight of anything in it, or withhold it from somebody who can
 * already open every record it lists.
 *
 * **The two scopes are evaluated independently and never collapsed.** This is
 * the subtle part and the one worth stating twice: an actor may hold
 * `parties.view` at `OFFICE` and `companies.view` at `ALL`, and the honest
 * answer is their own Office's Individuals together with every Office's
 * Companies. Taking the "widest" scope would show them Individuals they may not
 * open; taking the narrowest would hide Companies they may. Neither is a
 * ranking, because D-028 says scopes are a union of independent predicates, not
 * a ladder. So the query is built as two capability-specific branches joined by
 * `OR`, each carrying its own predicate.
 */
class PartyDirectoryController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PartyVisibility $visibility,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();

        $individualAccess = $this->resolver->resolve($actor, 'parties.view');
        $companyAccess = $this->resolver->resolve($actor, 'companies.view');

        $seesIndividuals = $this->visibility->hasUsableScope($individualAccess);
        $seesCompanies = $this->visibility->hasUsableScope($companyAccess);

        // Neither capability reaches anything, so there is no directory to show.
        // Refused outright rather than served as a reliably empty page.
        abort_unless($seesIndividuals || $seesCompanies, 403, 'You may not view the party directory.');

        $type = $request->query('party_type');
        $type = in_array($type, ['INDIVIDUAL', 'COMPANY'], true) ? $type : null;

        // A type filter narrows what the caller asked for; it never widens what
        // they may see. Asking for COMPANY without `companies.view` yields
        // nothing rather than bypassing the subtype permission.
        $includeIndividuals = $seesIndividuals && $type !== 'COMPANY';
        $includeCompanies = $seesCompanies && $type !== 'INDIVIDUAL';

        if (! $includeIndividuals && ! $includeCompanies) {
            // A legitimate empty result: the caller filtered to a type they
            // cannot see. No existence metadata is offered about it.
            return PartyDirectoryResource::collection(
                Party::query()->whereRaw('1 = 0')->paginate($this->perPage($request))
            );
        }

        $query = Party::query()
            ->with(['office', 'individual', 'company'])
            ->where(function (BuilderContract $outer) use (
                $actor, $individualAccess, $companyAccess, $includeIndividuals, $includeCompanies
            ): void {
                // One branch per capability, each carrying its own scope. The
                // union is what makes differing scopes come out right.
                if ($includeIndividuals) {
                    $outer->orWhere(fn (BuilderContract $branch) => $branch
                        ->where('party_type', 'INDIVIDUAL')
                        ->whereIn('id', $this->scopedIds($actor, $individualAccess, 'INDIVIDUAL')));
                }

                if ($includeCompanies) {
                    $outer->orWhere(fn (BuilderContract $branch) => $branch
                        ->where('party_type', 'COMPANY')
                        ->whereIn('id', $this->scopedIds($actor, $companyAccess, 'COMPANY')));
                }
            });

        if ($officeId = trim((string) $request->query('office_id', ''))) {
            // Narrows within what each capability already permits — it cannot
            // reach an Office a capability does not already reach.
            $query->where('office_id', $officeId);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            // Ordinary fields only. No identifier is searchable here: the
            // directory must not become the existence oracle that the
            // Office-scoped duplicate rules exist to prevent (D-084).
            $query->where(function (BuilderContract $inner) use ($search): void {
                $inner->whereLike('display_name', "%{$search}%")
                    ->orWhereLike('primary_phone', "%{$search}%")
                    ->orWhereLike('primary_email', "%{$search}%")
                    ->orWhereHas('individual', fn ($i) => $i->whereLike('full_name', "%{$search}%"))
                    ->orWhereHas('company', fn ($c) => $c
                        ->whereLike('legal_name', "%{$search}%")
                        ->orWhereLike('short_name', "%{$search}%"));
            });
        }

        $query->orderBy('display_name')->orderBy('id');

        return PartyDirectoryResource::collection(
            $query->paginate($this->perPage($request))->withQueryString()
        );
    }

    /**
     * The Party ids one capability reaches, as a subquery.
     *
     * Kept as SQL rather than a fetched list: an office-scoped caller's query
     * never selects another Office's rows, and the pagination total counts only
     * what they may see.
     *
     * @return Builder<Party>
     */
    private function scopedIds(
        User $actor,
        EffectiveAccess $access,
        string $partyType,
    ): Builder {
        return $this->visibility
            ->scope(Party::query()->where('party_type', $partyType), $actor, $access)
            ->select('parties.id');
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 20), 1), 100);
    }
}
