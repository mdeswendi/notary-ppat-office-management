<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Document\Actions\AttachDocument;
use App\Domains\Document\Actions\DetachDocument;
use App\Domains\Document\DocumentVisibility;
use App\Domains\Document\Enums\DocumentRelationType;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Party\PartyVisibility;
use App\Domains\Project\ProjectVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Document\DocumentRelationRequest;
use App\Models\Document;
use App\Models\Matter;
use App\Models\Party;
use App\Models\Project;
use App\Policies\DocumentPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a Document is attached to (M5.3, D-118).
 *
 * Thin (`CLAUDE.md` section 35): authorize, take validated input, call an Action,
 * return a payload. The junction rules live in the Actions and the reachability
 * rules in each domain's visibility class, where both can be read and tested
 * without HTTP.
 *
 * ## Attaching asks two questions, and both must answer yes
 *
 * **The Document side** answers to `documents.update` through
 * {@see DocumentPolicy::attach()} — attaching is a correction to a
 * document's own filing, not a new capability, so no code was added to the
 * canonical catalogue (the count stays at 177).
 *
 * **The record side** answers to that record's own view capability, resolved
 * through its own visibility class. `documents.update` is authority over a
 * document's filing; it is never authority to discover which records exist. An
 * unreachable target, a target in another Office, and a nonexistent one all
 * produce the same 422, because telling them apart would answer a question the
 * caller has no permission to ask.
 *
 * ## The Matter namespace comes from the row, and why that is not the D-101 hazard
 *
 * A Matter is reached under `notary.matters.view` or `ppat.matters.view` depending
 * on its own `domain` column. This is the second place in the repository that
 * reads the namespace from stored data rather than from a route — the first is
 * `DocumentController::matterReachable()`, added at M5.2 for the same reason.
 *
 * D-101 exists so a **caller** cannot choose which permission is checked. Here the
 * caller supplies an id and nothing else; the namespace comes from a row they
 * cannot influence, and the check that results is the *stricter* of the two rather
 * than either — they must hold the code for the domain the Matter actually is.
 * The alternative would have been `entity_type: notary_matter | ppat_matter`,
 * which puts the namespace in the request body and is precisely what D-101
 * forbids.
 *
 * ## Deliberately absent
 *
 * **No `GET /api/v1/{entity}/{id}/documents`.** That question is already answered
 * by `GET /api/v1/documents?project_id=…`, shipped at M5.2 and applied inside the
 * visibility-scoped query. A second address for one question is two surfaces that
 * must be kept in step, and the first divergence between them would be a bug.
 */
class DocumentRelationController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly DocumentVisibility $documents,
        private readonly PartyVisibility $parties,
        private readonly ProjectVisibility $projects,
        private readonly MatterVisibility $matters,
    ) {}

    /**
     * Everything this Document is attached to.
     *
     * **Stubs, not embedded records.** Each carries a name and an id — enough to
     * say where the document has been filed and to link there. Opening the record
     * is that surface's own decision.
     *
     * A record the caller cannot open still appears, and that is deliberate:
     * hiding it would make the list lie about where a document sits, and the stub
     * discloses nothing beyond a label the caller already reached through a
     * document they are authorized to read. No Party identity of any kind travels
     * here (D-082).
     */
    public function index(Request $request, string $document): JsonResponse
    {
        $record = $this->resolveDocument($request, $document);

        $this->authorize('viewRelations', $record);

        $record->load(['parties', 'projects', 'matters']);

        return response()->json(['data' => [
            'parties' => $record->parties->map(fn (Party $party): array => [
                'id' => $party->id,
                'entity_type' => DocumentRelationType::PARTY->value,
                'party_type' => $party->party_type->value,
                'label' => $party->display_name,
                'reference' => null,
                'attached_at' => $this->attachedAt($party),
            ])->values()->all(),

            'projects' => $record->projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'entity_type' => DocumentRelationType::PROJECT->value,
                'label' => $project->title,
                'reference' => $project->project_number,
                'attached_at' => $this->attachedAt($project),
            ])->values()->all(),

            'matters' => $record->matters->map(fn (Matter $matter): array => [
                'id' => $matter->id,
                'entity_type' => DocumentRelationType::MATTER->value,
                'domain' => $matter->domain->value,
                'label' => $matter->title,
                'reference' => $matter->matter_number,
                'attached_at' => $this->attachedAt($matter),
            ])->values()->all(),
        ]]);
    }

    /**
     * Attach this Document to a Party, Project or Matter.
     */
    public function store(
        DocumentRelationRequest $request,
        string $document,
        AttachDocument $attach,
    ): JsonResponse {
        $record = $this->resolveDocument($request, $document);

        $this->authorize('attach', $record);

        $type = $request->relationType();
        $target = $this->resolveTarget($request, $type, $request->entityId());

        $attach->handle($request->user(), $record, $type, $target);

        return $this->index($request, $document)->setStatusCode(201);
    }

    /**
     * Remove an attachment.
     *
     * `DELETE` with a body, because the pair being removed is two identifiers and
     * neither belongs in the path: `/documents/{id}/relations/{type}/{entityId}`
     * would put a permission-selecting value into an address, and a query string
     * would put record identifiers into logs and browser history.
     */
    public function destroy(
        DocumentRelationRequest $request,
        string $document,
        DetachDocument $detach,
    ): JsonResponse {
        $record = $this->resolveDocument($request, $document);

        $this->authorize('attach', $record);

        $type = $request->relationType();
        $target = $this->resolveTarget($request, $type, $request->entityId());

        $detach->handle($request->user(), $record, $type, $target);

        return $this->index($request, $document);
    }

    /**
     * Find a Document the caller may reach, or 404.
     *
     * Resolved **through canonical visibility** rather than a bare lookup, so an
     * unreachable Document is indistinguishable from a nonexistent one. Soft
     * deleted rows are excluded by the model's global scope.
     */
    private function resolveDocument(Request $request, string $documentId): Document
    {
        $actor = $request->user();

        $record = $this->documents->scope(
            Document::query()->whereKey($documentId),
            $actor,
            $this->resolver->resolve($actor, 'documents.view'),
        )->first();

        if ($record === null) {
            abort(404);
        }

        return $record;
    }

    /**
     * Re-resolve the target through its own domain's visibility.
     *
     * **The submitted id is never trusted.** Four failures — nonexistent, another
     * Office, unreachable under that domain's view capability, or archived —
     * produce one indistinguishable 422 per the reasoning in the class docblock.
     *
     * The Office check is explicit as well as structural: the composite foreign
     * keys would refuse a cross-office row regardless, but a field error is a
     * better answer than a 500.
     */
    private function resolveTarget(Request $request, DocumentRelationType $type, string $entityId): Model
    {
        $actor = $request->user();

        $target = match ($type) {
            DocumentRelationType::PARTY => $this->parties->scope(
                Party::query()->whereKey($entityId)->where('office_id', $actor->office_id),
                $actor,
                $this->resolver->resolve($actor, 'parties.view'),
            )->first(),

            DocumentRelationType::PROJECT => $this->projects->scope(
                Project::query()->whereKey($entityId)->where('office_id', $actor->office_id),
                $actor,
                $this->resolver->resolve($actor, 'projects.view'),
            )->first(),

            DocumentRelationType::MATTER => $this->resolveMatter($request, $entityId),
        };

        if ($target === null) {
            abort(422, 'Select a record you can open in your own office.');
        }

        return $target;
    }

    /**
     * A Matter reachable under **its own domain's** view capability.
     *
     * The domain is read from the stored row, never from the request. See the
     * class docblock for why that is the stricter check rather than the D-101
     * hazard.
     */
    private function resolveMatter(Request $request, string $matterId): ?Matter
    {
        $actor = $request->user();

        $matter = Matter::query()
            ->whereKey($matterId)
            ->where('office_id', $actor->office_id)
            ->first(['id', 'domain', 'office_id']);

        if ($matter === null) {
            return null;
        }

        $domain = $matter->domain instanceof MatterDomain
            ? $matter->domain
            : MatterDomain::from((string) $matter->domain);

        return $this->matters->scope(
            Matter::query()->whereKey($matterId),
            $actor,
            $this->resolver->resolve($actor, $domain->permission('view')),
        )->first();
    }

    /**
     * When the attachment was made, read from the pivot.
     *
     * `office_id` is deliberately not exposed alongside it — it is a constraint
     * carrier, not information about the relationship.
     */
    private function attachedAt(Model $related): ?string
    {
        $pivot = $related->getRelationValue('pivot');

        if ($pivot === null) {
            return null;
        }

        $value = $pivot->getAttribute('attached_at');

        return $value === null ? null : (string) $value;
    }
}
