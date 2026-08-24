<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Document\DocumentVisibility;
use App\Domains\Notary\Actions\FileMinuta;
use App\Domains\Notary\Actions\UpdateMinuta;
use App\Domains\Notary\NotaryDeedVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notary\StoreMinutaRequest;
use App\Http\Requests\Notary\UpdateMinutaRequest;
use App\Http\Resources\NotaryMinutaResource;
use App\Models\Document;
use App\Models\NotaryDeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Minuta Akta — where a deed's original is filed (M6.3, D-120).
 *
 * Thin (`CLAUDE.md` section 35): authorize, take validated input, call an Action,
 * return a Resource.
 *
 * **Nested under the deed, with no top-level `/notary/minuta` root.** The M6.3 brief
 * proposed `PUT /notary/minuta/{minuta}` and `DELETE /notary/minuta/{minuta}`; this
 * follows the M4.5 participation convention instead (D-105), which is explicit that
 * there is *"deliberately no top-level collection to reach a row without naming the
 * parent it belongs to"*. A Minuta has no independent existence — one per deed,
 * reached exactly as its deed is — so an address that omits the deed would be an
 * address for something the domain does not have.
 *
 * That also makes the singular route honest: `GET /notary/deeds/{deed}/minuta` is
 * **one record or 404**, never a collection.
 *
 * **An unreachable deed is a 404**, resolved through canonical deed visibility, so a
 * deed the caller may not reach behaves as though nothing is there.
 *
 * ## Three acts, three capabilities, and three codes deliberately unused
 *
 * ```text
 * show     notary.minuta.view
 * store    notary.minuta.create   + documents.view on the file
 * update   notary.minuta.update   + documents.view on a replacement file
 * ```
 *
 * **There is no `DELETE`.** The catalogue defines `view`, `create`, `update`,
 * `archive` and `release` and **no `notary.minuta.delete`** — verified against the
 * live registry — and `notary_minuta` has no `deleted_at` column, the ERD omitting
 * it. The brief asked for a soft delete restricted to `DRAFT`, which would need both.
 * A Minuta filed against the wrong deed is corrected by replacing `document_id`.
 *
 * **There is no `archive` and no `release`.** Both codes exist and both stay
 * unimplemented, because *"What triggers Minuta Akta archiving, and what release
 * conditions apply?"* is open question four in `08_NOTARY_WORKFLOW.md` section 6.
 * Shipping the endpoints before the rule is written would be inventing the rule.
 *
 * ## The Document is re-resolved, never trusted
 *
 * `document_id` arrives from the caller, so it is looked up through **canonical
 * Document visibility** before it is written: `notary.minuta.create` is authority to
 * record a filing, never authority to discover which Documents exist. An unreachable
 * Document, one in another Office and one that does not exist all produce the same
 * field error — the D-118 two-question rule, applied to a column rather than a
 * junction.
 */
class NotaryMinutaController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly NotaryDeedVisibility $deeds,
        private readonly DocumentVisibility $documents,
    ) {}

    /**
     * The deed's filing record, or 404.
     *
     * **404 rather than an empty payload**, because a deed with no Minuta filed and a
     * deed whose Minuta the caller may not see are different situations, and the
     * interface renders them differently: the first offers a control to file one, the
     * second offers nothing.
     */
    public function show(Request $request, string $deed): NotaryMinutaResource
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('viewMinuta', $record);

        $minuta = $record->minuta()->with('document')->first();

        if ($minuta === null) {
            abort(404);
        }

        return new NotaryMinutaResource($minuta, $this->capabilitiesFor($record));
    }

    /**
     * File the deed's original.
     *
     * **One per deed, enforced by a unique index and answered as a field error.**
     * Filing a second is refused rather than silently replacing the first — replacing
     * the file is what `update` is for, and a caller who meant that should say so.
     */
    public function store(StoreMinutaRequest $request, string $deed, FileMinuta $file): JsonResponse
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('createMinuta', $record);

        if ($record->minuta()->exists()) {
            throw ValidationException::withMessages([
                'document_id' => __('A minuta has already been filed for this deed.'),
            ]);
        }

        $document = $this->resolveDocument($request->documentId(), $record);

        $minuta = $file->handle($request->user(), $record, $document, $request->minutaAttributes());

        return (new NotaryMinutaResource(
            $minuta->load('document'),
            $this->capabilitiesFor($record),
        ))->response()->setStatusCode(201);
    }

    /**
     * Correct the filing — the shelf reference, or the file itself.
     */
    public function update(UpdateMinutaRequest $request, string $deed, UpdateMinuta $update): NotaryMinutaResource
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('updateMinuta', $record);

        $minuta = $record->minuta()->first();

        if ($minuta === null) {
            abort(404);
        }

        $documentId = $request->documentId();

        $document = $documentId === null ? null : $this->resolveDocument($documentId, $record);

        $updated = $update->handle($request->user(), $minuta, $document, $request->minutaAttributes());

        return new NotaryMinutaResource(
            $updated->load('document'),
            $this->capabilitiesFor($record),
        );
    }

    /**
     * Find a Deed the caller may reach, or 404.
     */
    private function resolveDeed(string $deedId): NotaryDeed
    {
        $actor = request()->user();

        $record = $this->deeds->scope(
            NotaryDeed::query()->whereKey($deedId),
            $actor,
            $this->resolver->resolve($actor, 'notary.deeds.view'),
        )->first();

        if ($record === null) {
            abort(404);
        }

        return $record;
    }

    /**
     * Find a Document the caller may reach **and** that belongs to the deed's Office.
     *
     * Resolved through canonical Document visibility, so this endpoint never becomes
     * a way to discover which Documents exist. The Office check is applied here as
     * well as by the composite foreign key, so a cross-office file is a field error
     * rather than a 500.
     *
     * All three failures — unreachable, wrong Office, nonexistent — answer alike.
     */
    private function resolveDocument(string $documentId, NotaryDeed $deed): Document
    {
        $actor = request()->user();

        $document = $this->documents->scope(
            Document::query()->whereKey($documentId)->where('office_id', $deed->office_id),
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

    /**
     * @return array<string, bool>
     */
    private function capabilitiesFor(NotaryDeed $deed): array
    {
        $actor = request()->user();

        $reaches = fn (string $permission): bool => $this->deeds->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $deed,
        );

        // One row, so there is no page to resolve in bulk — unlike the deed list,
        // where `capabilityMap()` exists to avoid an N+1.
        return [
            'can_update' => $reaches('notary.minuta.update'),
        ];
    }
}
