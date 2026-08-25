<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Document\DocumentVisibility;
use App\Domains\Party\ParticipantVisibility;
use App\Domains\Ppat\Actions\AddWarkahItem;
use App\Domains\Ppat\Actions\AttachWarkahDocument;
use App\Domains\Ppat\Actions\DetachWarkahDocument;
use App\Domains\Ppat\Actions\RemoveWarkahItem;
use App\Domains\Ppat\Actions\StartWarkah;
use App\Domains\Ppat\Actions\UpdateWarkahItem;
use App\Domains\Ppat\PpatDeedVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ppat\AttachWarkahDocumentRequest;
use App\Http\Requests\Ppat\StoreWarkahItemRequest;
use App\Http\Requests\Ppat\UpdateWarkahItemRequest;
use App\Http\Resources\PpatWarkahItemResource;
use App\Models\Document;
use App\Models\Party;
use App\Models\PpatDeed;
use App\Models\PpatWarkah;
use App\Models\PpatWarkahItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The lines of a Warkah, and the Documents filed against them (M7.4, D-121).
 *
 * ## Nested under the deed, and never reachable alone
 *
 * The M7.4 brief proposed `PUT /api/v1/ppat/warkah/items/{item}` — a top-level address
 * that omits the deed. That is the shape D-105 refused for participation: *"there is
 * deliberately no top-level `/matter-parties` collection to reach a row without naming
 * the Matter it belongs to."* A Warkah line has no existence apart from its bundle, and
 * a bundle none apart from its deed, so every address here names all three.
 *
 * The same reason M6.3 nested Minuta under its deed and M7.3 nested the chain of title
 * under its Property.
 *
 * ## Three capabilities, and each is a different job
 *
 * ```text
 * index                      ppat.warkah.view     read the checklist
 * store, update, destroy     ppat.warkah.update   write the checklist
 * attach, detach             ppat.warkah.upload   produce the evidence
 * ```
 *
 * **`update` and `upload` are separate on purpose.** Writing down *which* documents a
 * transaction needs is a different job from producing them, and an office that grants
 * one without the other is saying something real. `upload` covers both directions:
 * there is no `ppat.warkah.detach` in the catalogue, and removing a file misfiled
 * against the wrong line is the correction of the upload rather than a new act.
 *
 * ## Two resolutions that keep capabilities from leaking
 *
 * **The Party is resolved through canonical Party visibility**, per subtype, so
 * `ppat.warkah.update` never becomes a way to discover which Parties exist.
 *
 * **The Document is resolved through canonical `documents.view` visibility**, so
 * `ppat.warkah.upload` never becomes a way to discover which files exist. Both answer
 * one indistinguishable field error for an unreachable, wrong-Office or nonexistent
 * id — the construction every resolve-through-visibility path in this repository uses.
 *
 * Neither confers anything onward: attaching a Document does not make it readable, and
 * naming a Party does not make their record openable. Those answer to their own
 * capabilities every time (D-115, D-082).
 */
class PpatWarkahItemController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PpatDeedVisibility $visibility,
        private readonly ParticipantVisibility $participants,
        private readonly DocumentVisibility $documents,
    ) {}

    /**
     * The checklist, in the order the office arranged it.
     *
     * Party visibility is computed in **bulk**, one query per subtype branch — the
     * `MatterPartyController` construction, and for the same reason: the actor's
     * effective access does not vary by row, so a per-row check would be the N+1 M2.6
     * measured and every surface since has avoided.
     *
     * A line whose Party the actor cannot open **still appears**: it is the office's own
     * checklist, and hiding it would misreport what the transaction needs.
     */
    public function index(Request $request, string $deed): JsonResponse
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('view', [PpatWarkah::class, $record]);

        $warkah = $this->existingWarkah($record);

        $items = $warkah === null
            ? new Collection
            : PpatWarkahItem::query()
                ->where('warkah_id', $warkah->getKey())
                ->with([
                    'party' => fn ($query) => $query->withTrashed(),
                    'documents',
                ])
                ->orderBy('sequence_no')
                ->orderBy('id')
                ->get();

        $viewable = $this->participants->viewableKeys(
            $items->pluck('party')->filter()->all(),
            $request->user(),
        );

        $canManage = $request->user()->can('manage', [PpatWarkah::class, $record]);
        $canUpload = $request->user()->can('upload', [PpatWarkah::class, $record]);

        return response()->json([
            'data' => $items->map(fn (PpatWarkahItem $item): array => (
                new PpatWarkahItemResource(
                    $item,
                    isset($viewable[$item->party_id]),
                    ['can_manage' => $canManage, 'can_upload' => $canUpload],
                )
            )->toArray($request))->all(),

            'meta' => [
                'total' => $items->count(),
                'can_manage' => $canManage,
                'can_upload' => $canUpload,

                // The fraction the percentage came from. A reader who sees *7 of 9
                // lines* understands what is being counted; one who sees *78%* does
                // not.
                'collected' => $items->filter(
                    fn (PpatWarkahItem $item): bool => $item->documents->isNotEmpty()
                )->count(),

                'completeness_percentage' => $warkah?->completeness_percentage ?? 0,

                // Whether the office has started a bundle at all. `false` is an empty
                // state, not a failure — the first line started starts the bundle.
                'warkah_started' => $warkah !== null,
            ],
        ]);
    }

    /**
     * Add a line, starting the bundle if the office has not yet.
     *
     * There is no `ppat.warkah.create` in the catalogue, so composing the checklist is
     * what brings the bundle into existence — see {@see StartWarkah}.
     */
    public function store(
        StoreWarkahItemRequest $request,
        string $deed,
        StartWarkah $start,
        AddWarkahItem $add,
    ): JsonResponse {
        $record = $this->resolveDeed($deed);

        $this->authorize('manage', [PpatWarkah::class, $record]);

        $warkah = $start->handle($record);

        $party = $this->resolveParty($request->partyId(), $record, $request);

        $item = $add->handle($request->user(), $warkah, $request->itemAttributes(), $party);

        return (new PpatWarkahItemResource(
            $item->load(['party' => fn ($query) => $query->withTrashed(), 'documents']),
            $party !== null,
            ['can_manage' => true, 'can_upload' => $request->user()->can('upload', [PpatWarkah::class, $record])],
        ))->response()->setStatusCode(201);
    }

    public function update(
        UpdateWarkahItemRequest $request,
        string $deed,
        string $item,
        UpdateWarkahItem $update,
    ): PpatWarkahItemResource {
        $record = $this->resolveDeed($deed);

        $this->authorize('manage', [PpatWarkah::class, $record]);

        $line = $this->resolveItem($record, $item);

        $party = $request->partyGiven()
            ? $this->resolveParty($request->partyId(), $record, $request)
            : null;

        $updated = $update->handle(
            $request->user(),
            $line,
            $request->itemAttributes(),
            $request->partyGiven(),
            $party,
        );

        return new PpatWarkahItemResource(
            $updated->load(['party' => fn ($query) => $query->withTrashed(), 'documents']),
            true,
            ['can_manage' => true, 'can_upload' => $request->user()->can('upload', [PpatWarkah::class, $record])],
        );
    }

    /**
     * Take a line off the checklist.
     *
     * **A hard delete of the line and its junction rows**, because
     * `ppat_warkah_items` has no `deleted_at` in the ERD. The Documents themselves are
     * untouched — see {@see RemoveWarkahItem}.
     */
    public function destroy(
        Request $request,
        string $deed,
        string $item,
        RemoveWarkahItem $remove,
    ): JsonResponse {
        $record = $this->resolveDeed($deed);

        $this->authorize('manage', [PpatWarkah::class, $record]);

        $remove->handle($request->user(), $this->resolveItem($record, $item));

        return response()->json(status: 204);
    }

    /**
     * File a Document against a line.
     *
     * The one act that moves completeness up — see {@see AttachWarkahDocument}.
     */
    public function attachDocument(
        AttachWarkahDocumentRequest $request,
        string $deed,
        string $item,
        AttachWarkahDocument $attach,
    ): JsonResponse {
        $record = $this->resolveDeed($deed);

        $this->authorize('upload', [PpatWarkah::class, $record]);

        $line = $this->resolveItem($record, $item);
        $document = $this->resolveDocument($request->documentId(), $request);

        $attach->handle($request->user(), $line, $document);

        return (new PpatWarkahItemResource(
            $line->fresh()->load(['party' => fn ($query) => $query->withTrashed(), 'documents']),
            true,
            ['can_manage' => $request->user()->can('manage', [PpatWarkah::class, $record]), 'can_upload' => true],
        ))->response()->setStatusCode(201);
    }

    /**
     * Stop treating a Document as satisfying a line.
     *
     * The junction row only — never the Document, never the line.
     */
    public function detachDocument(
        Request $request,
        string $deed,
        string $item,
        string $document,
        DetachWarkahDocument $detach,
    ): JsonResponse {
        $record = $this->resolveDeed($deed);

        $this->authorize('upload', [PpatWarkah::class, $record]);

        $line = $this->resolveItem($record, $item);

        $attached = $line->documents()->whereKey($document)->first();

        if ($attached === null) {
            abort(404);
        }

        $detach->handle($request->user(), $line, $attached);

        return response()->json(status: 204);
    }

    /**
     * Find a PPAT Deed whose bundle the caller may reach, or 404.
     *
     * **Always resolved under `ppat.warkah.view`, never under the act's own code**, and
     * the distinction decides which of two answers a caller gets:
     *
     * ```text
     * no Warkah capability at all    404   the bundle is not a thing you can see
     * view but not update            403   you may read this and not write it
     * ```
     *
     * Resolving under the act's capability would collapse both into 404 and tell a
     * legitimate reader that nothing is there. Reachability is one question — answered
     * here, and answered 404 so an unreachable bundle stays indistinguishable from a
     * nonexistent one (D-098) — and authority to act is the Policy's, answered 403.
     * `PpatDeedController` and `PropertyController` both draw the line in the same
     * place.
     *
     * The scope predicate runs over `ppat_deeds` regardless, because that is where a
     * Warkah's reach comes from.
     */
    private function resolveDeed(string $deedId): PpatDeed
    {
        $actor = request()->user();

        $record = $this->visibility->scope(
            PpatDeed::query()->whereKey($deedId),
            $actor,
            $this->resolver->resolve($actor, 'ppat.warkah.view'),
        )->first();

        if ($record === null) {
            abort(404);
        }

        return $record;
    }

    private function existingWarkah(PpatDeed $deed): ?PpatWarkah
    {
        return PpatWarkah::query()->where('ppat_deed_id', $deed->getKey())->first();
    }

    /**
     * A line belonging to this deed's bundle, or 404.
     *
     * Scoped to the parent rather than looked up by id alone, so no address reaches a
     * line without naming the deed it belongs to.
     */
    private function resolveItem(PpatDeed $deed, string $itemId): PpatWarkahItem
    {
        $warkah = $this->existingWarkah($deed);

        if ($warkah === null) {
            abort(404);
        }

        $item = PpatWarkahItem::query()
            ->where('warkah_id', $warkah->getKey())
            ->whereKey($itemId)
            ->first();

        if ($item === null) {
            abort(404);
        }

        return $item;
    }

    /**
     * A Party the actor may reach, per subtype, or one indistinguishable field error.
     */
    private function resolveParty(?string $partyId, PpatDeed $deed, Request $request): ?Party
    {
        if ($partyId === null) {
            return null;
        }

        $party = Party::query()
            ->whereKey($partyId)
            ->where('office_id', $deed->office_id)
            ->first();

        $reachable = $party !== null
            && $this->participants->viewableKeys([$party], $request->user()) !== [];

        if (! $reachable) {
            throw ValidationException::withMessages([
                'party_id' => __('validation.exists', ['attribute' => 'party id']),
            ]);
        }

        return $party;
    }

    /**
     * A Document the actor may reach, or one indistinguishable field error.
     *
     * **Reachability under `documents.view`, not existence.** An `exists` rule would
     * answer *"that document is real but not yours"*, which is the existence oracle
     * this construction avoids.
     */
    private function resolveDocument(string $documentId, Request $request): Document
    {
        $actor = $request->user();

        $document = $this->documents->scope(
            Document::query()->whereKey($documentId),
            $actor,
            $this->resolver->resolve($actor, 'documents.view'),
        )->first();

        if ($document === null) {
            throw ValidationException::withMessages([
                'document_id' => __('validation.exists', ['attribute' => 'document id']),
            ]);
        }

        return $document;
    }
}
