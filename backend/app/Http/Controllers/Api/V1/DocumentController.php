<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Document\Actions\ArchiveDocument;
use App\Domains\Document\Actions\DeleteDocument;
use App\Domains\Document\Actions\DownloadDocument;
use App\Domains\Document\Actions\UpdateDocument;
use App\Domains\Document\Actions\UploadDocument;
use App\Domains\Document\Actions\VerifyDocument;
use App\Domains\Document\DocumentVisibility;
use App\Domains\Document\Enums\DocumentStatus;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Party\PartyVisibility;
use App\Domains\Project\ProjectVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Document\UpdateDocumentRequest;
use App\Http\Requests\Document\UploadDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Matter;
use App\Models\Party;
use App\Models\Project;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document Management (M5.2, D-117).
 *
 * Thin (`CLAUDE.md` section 35): authorize, take validated input, call an Action,
 * return a Resource. Scope rules live in {@see DocumentVisibility}, status rules
 * in {@see DocumentStatus}, and mutation rules in the Actions, where all three can
 * be read and tested without HTTP.
 *
 * **One surface, not two.** Unlike Matter, there is no `/notary/documents` and no
 * `/ppat/documents`: `documents.*` is a single canonical namespace with no domain
 * split, so there is nothing for a route prefix to select (D-101 governs Matter
 * because *that* catalogue is split; it has no bearing here).
 *
 * **Each act answers to its own capability**, and none implies another —
 * `documents.upload`, `download`, `update`, `verify`, `archive` and `delete` are
 * six separate codes, so six separate abilities and six separate routes. The
 * D-091 discipline every domain in this repository follows.
 *
 * **Attachment targets are re-resolved through their own visibility.** A caller
 * who names a Party, Project or Matter must be able to reach it under that
 * domain's own view capability — `documents.upload` is authority to file a
 * document, never authority to discover which records exist. An unreachable target
 * and a nonexistent one produce the same 422, because telling them apart would
 * answer a question the caller has no permission to ask.
 *
 * For a Matter the namespace is read from the Matter's own `domain` column, which
 * is the one place in the repository that happens. It is not the D-101 hazard:
 * that rule exists so a **caller** cannot choose which permission is checked, and
 * here the caller supplies only an id while the namespace comes from the stored
 * row they cannot influence. The effect is a stricter check, not a looser one —
 * they must hold the code for the domain the Matter actually is.
 */
class DocumentController extends Controller
{
    /**
     * The mutation abilities the interface asks about, and the capability each
     * one answers to.
     *
     * `can_download` is deliberately **not** here: it cannot be answered by the
     * bulk reach query, because the Policy additionally refuses every sensitive
     * document (D-115). It is computed per row from `is_sensitive`, which the row
     * already carries.
     */
    private const CAPABILITIES = [
        'can_update' => 'documents.update',
        'can_verify' => 'documents.verify',
        'can_archive' => 'documents.archive',
        'can_delete' => 'documents.delete',
        'can_upload' => 'documents.upload',
        'can_download' => 'documents.download',
    ];

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly DocumentVisibility $visibility,
        private readonly PartyVisibility $parties,
        private readonly ProjectVisibility $projects,
        private readonly MatterVisibility $matters,
    ) {}

    /**
     * Documents the caller may see.
     *
     * Visibility is applied **in the query**, so a scoped caller's SQL never
     * selects a row they may not open — the pagination total counts only what they
     * may see, and no filter can widen it.
     *
     * **Sensitive documents appear in the list.** `is_sensitive` is not a
     * visibility predicate (D-116); an actor who may not see them is refused by
     * `documents.sensitive.view` at the Policy, and the list already applies that
     * — see {@see reachableSensitively()}. What a *stub* for an unreachable
     * sensitive document may carry is a genuinely open question the M5 lock
     * records, so M5.2 does not render one: such rows are excluded outright rather
     * than half-shown.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Document::class);

        $actor = $request->user();

        $query = $this->visibility->scope(
            Document::query()->with(['office', 'creator']),
            $actor,
            $this->resolver->resolve($actor, 'documents.view'),
        );

        // A sensitive document is reachable only to an actor who also holds
        // `documents.sensitive.view` (D-115). Applied as a query condition rather
        // than a post-filter, so pagination totals stay honest.
        if (! $this->reachableSensitively($request)) {
            $query->where('is_sensitive', false);
        }

        $this->applyFilters($query, $request);

        $sort = $this->sortColumn($request);
        $direction = strtolower((string) $request->query('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction)->orderBy('id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $page = $query->paginate($perPage)->withQueryString();

        $capabilities = $this->capabilityMap(collect($page->items()), $request);

        return DocumentResource::collection($page->through(
            fn (Document $document): DocumentResource => new DocumentResource(
                $document,
                $capabilities[$document->getKey()] ?? [],
            )
        ));
    }

    /**
     * File a Document and its first version.
     */
    public function store(UploadDocumentRequest $request, UploadDocument $upload): JsonResponse
    {
        $this->authorize('create', [Document::class, $request->user()->office_id]);

        $relations = $this->resolveRelations($request, $request->relations());

        $document = $upload->handle(
            $request->user(),
            $request->file('file'),
            $request->documentAttributes(),
            $relations,
        );

        return (new DocumentResource(
            $this->loadForDetail($document),
            $this->capabilitiesFor($document, $request),
        ))->response()->setStatusCode(201);
    }

    /**
     * Open a Document's metadata. Never its file.
     */
    public function show(Request $request, string $document): DocumentResource
    {
        $record = $this->resolveDocument($document);

        $this->authorize('view', $record);

        return new DocumentResource(
            $this->loadForDetail($record),
            $this->capabilitiesFor($record, $request),
        );
    }

    /**
     * Stream the current file to an authorized caller.
     *
     * **Every sensitive download is refused here**, whatever the actor holds,
     * because D-115 keeps that surface closed until an audit store exists. The
     * Policy carries the gate so the answer is the same one `can_download`
     * reported — the interface never offers a button the endpoint will refuse.
     */
    public function download(Request $request, string $document, DownloadDocument $download): StreamedResponse
    {
        $record = $this->resolveDocument($document);

        $this->authorize('download', $record);

        return $download->handle($record);
    }

    /**
     * Correct a Document's metadata.
     */
    public function update(
        UpdateDocumentRequest $request,
        string $document,
        UpdateDocument $update,
    ): DocumentResource {
        $record = $this->resolveDocument($document);

        $this->authorize('update', $record);

        $updated = $update->handle($request->user(), $record, $request->documentAttributes());

        return new DocumentResource(
            $this->loadForDetail($updated),
            $this->capabilitiesFor($updated, $request),
        );
    }

    public function verify(Request $request, string $document, VerifyDocument $verify): DocumentResource
    {
        $record = $this->resolveDocument($document);

        $this->authorize('verify', $record);

        $updated = $verify->handle($request->user(), $record);

        return new DocumentResource(
            $this->loadForDetail($updated),
            $this->capabilitiesFor($updated, $request),
        );
    }

    public function archive(Request $request, string $document, ArchiveDocument $archive): DocumentResource
    {
        $record = $this->resolveDocument($document);

        $this->authorize('archive', $record);

        $updated = $archive->handle($request->user(), $record);

        return new DocumentResource(
            $this->loadForDetail($updated),
            $this->capabilitiesFor($updated, $request),
        );
    }

    /**
     * Remove a Document nobody has verified yet.
     *
     * 204, and the body is deliberately empty: the record is gone from every
     * ordinary query, so returning a representation of it would invite a client to
     * keep rendering one.
     */
    public function destroy(Request $request, string $document, DeleteDocument $delete): Response
    {
        $record = $this->resolveDocument($document);

        $this->authorize('delete', $record);

        $delete->handle($request->user(), $record);

        return response()->noContent();
    }

    /**
     * What the upload form needs to render.
     *
     * **`document_types` are suggestions, not a catalogue**, and nothing validates
     * against them (D-115, D-116). `document_type_code` is opaque; an office that
     * files something this list does not name may type it, and the request accepts
     * it. The list exists so the common cases are one click rather than a guess at
     * spelling — a real `document_types` master table is a design with an owner and
     * is not M5's to invent.
     *
     * Authorized by `documents.upload`, which is the only capability that can act
     * on any of this. `documents.update` is deliberately not an alternative: the
     * payload is upload-shaped, and an actor who may only correct metadata needs
     * the statuses, which the list surface already gives them.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('create', [Document::class, $request->user()->office_id]);

        return response()->json(['data' => [
            'document_types' => [
                'KTP', 'KK', 'NPWP', 'AKTA', 'SK', 'SERTIPIKAT', 'PBB', 'BPHTB', 'LAINNYA',
            ],
            'statuses' => DocumentStatus::values(),
            'mime_types' => UploadDocumentRequest::ALLOWED_MIME_TYPES,
            'max_upload_kilobytes' => UploadDocumentRequest::maxKilobytes(),
        ]]);
    }

    /**
     * Find a Document the caller may reach, or 404.
     *
     * Resolved **through canonical visibility** rather than a bare lookup, so an
     * unreachable Document is indistinguishable from a nonexistent one. Soft
     * deleted rows are excluded by the model's global scope, so a removed document
     * answers 404 as well.
     */
    private function resolveDocument(string $documentId): Document
    {
        $record = $this->visibility->scope(
            Document::query()->whereKey($documentId),
            request()->user(),
            $this->resolver->resolve(request()->user(), 'documents.view'),
        )->first();

        if ($record === null) {
            abort(404);
        }

        return $record;
    }

    /**
     * @param  Builder<Document>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($search = trim((string) $request->query('search', ''))) {
            // Grouped so the search cannot escape the visibility constraint.
            //
            // `document_number` is searchable and safe: ordinary office
            // identification rather than sensitive identity, and the scope
            // predicate still bounds every row. Because references are unique only
            // within an Office (D-116), one reference may legitimately match rows
            // in several Offices for an ALL-scoped caller — the search does not
            // pretend otherwise.
            $query->where(function (BuilderContract $inner) use ($search): void {
                $inner->whereLike('title', "%{$search}%")
                    ->orWhereLike('document_number', "%{$search}%");
            });
        }

        // Unrecognized filter values are ignored rather than erroring: a stale
        // bookmark should show the unfiltered list, not a 422.
        if (DocumentStatus::tryFrom((string) $request->query('status', '')) !== null) {
            $query->where('status', $request->query('status'));
        }

        if (($type = trim((string) $request->query('document_type_code', ''))) !== '') {
            $query->where('document_type_code', $type);
        }

        // Only an explicit "true" or "false" filters; anything else is no filter.
        $sensitive = $request->query('is_sensitive');

        if (in_array($sensitive, ['true', '1', 'false', '0'], true)) {
            $query->where('is_sensitive', in_array($sensitive, ['true', '1'], true));
        }

        // Relation filters, applied as `whereHas` against the junctions. The
        // caller supplies an id they already hold; the visibility predicate above
        // still bounds which documents can come back, so this narrows and can
        // never widen.
        foreach ([
            'party_id' => 'parties',
            'project_id' => 'projects',
            'matter_id' => 'matters',
        ] as $parameter => $relation) {
            if (($value = trim((string) $request->query($parameter, ''))) === '') {
                continue;
            }

            $query->whereHas($relation, function (BuilderContract $inner) use ($value): void {
                $inner->whereKey($value);
            });
        }
    }

    /**
     * An allow-list, because a sort column is a column name reaching SQL.
     */
    private function sortColumn(Request $request): string
    {
        $requested = (string) $request->query('sort_by', 'created_at');

        return in_array($requested, ['created_at', 'document_number', 'title'], true)
            ? $requested
            : 'created_at';
    }

    /**
     * Whether this actor may reach sensitive documents at all.
     */
    private function reachableSensitively(Request $request): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($request->user(), 'documents.sensitive.view')
        );
    }

    /**
     * Re-resolve each submitted relation through its own domain's visibility.
     *
     * **The submitted ids are never trusted.** A record from another Office,
     * unreachable to this actor, or simply nonexistent produces one
     * indistinguishable 422 per field. The composite foreign keys refuse a
     * cross-office attachment regardless; this exists so the caller gets a field
     * error rather than a 500.
     *
     * @param  array{party_id: string|null, project_id: string|null, matter_id: string|null}  $relations
     * @return array{party_id: string|null, project_id: string|null, matter_id: string|null}
     */
    private function resolveRelations(Request $request, array $relations): array
    {
        $actor = $request->user();

        if ($relations['party_id'] !== null && ! $this->partyReachable($request, $relations['party_id'])) {
            abort(422, 'Select a party you can open in your own office.');
        }

        if ($relations['project_id'] !== null) {
            $reachable = $this->projects->scope(
                Project::query()->whereKey($relations['project_id'])->where('office_id', $actor->office_id),
                $actor,
                $this->resolver->resolve($actor, 'projects.view'),
            )->exists();

            if (! $reachable) {
                abort(422, 'Select a project you can open in your own office.');
            }
        }

        if ($relations['matter_id'] !== null && ! $this->matterReachable($request, $relations['matter_id'])) {
            abort(422, 'Select a matter you can open in your own office.');
        }

        return $relations;
    }

    private function partyReachable(Request $request, string $partyId): bool
    {
        $actor = $request->user();

        return $this->parties->scope(
            Party::query()->whereKey($partyId)->where('office_id', $actor->office_id),
            $actor,
            $this->resolver->resolve($actor, 'parties.view'),
        )->exists();
    }

    /**
     * Whether the actor may reach this Matter under **its own domain's** view code.
     *
     * The namespace comes from the stored `domain` column rather than from the
     * request. See the class docblock: the caller supplies only an id, so they
     * cannot choose which permission is checked, and the check is the stricter of
     * the two rather than either.
     */
    private function matterReachable(Request $request, string $matterId): bool
    {
        $actor = $request->user();

        $matter = Matter::query()
            ->whereKey($matterId)
            ->where('office_id', $actor->office_id)
            ->first(['id', 'domain', 'office_id']);

        if ($matter === null) {
            return false;
        }

        $domain = $matter->domain instanceof MatterDomain
            ? $matter->domain
            : MatterDomain::from((string) $matter->domain);

        return $this->matters->scope(
            Matter::query()->whereKey($matterId),
            $actor,
            $this->resolver->resolve($actor, $domain->permission('view')),
        )->exists();
    }

    private function loadForDetail(Document $document): Document
    {
        return $document->load([
            'office',
            'creator',
            'archiver',
            'currentVersion.uploader',
            'versions.uploader',
            'parties',
            'projects',
            'matters',
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function capabilitiesFor(Document $document, Request $request): array
    {
        return $this->capabilityMap(collect([$document]), $request)[$document->getKey()] ?? [];
    }

    /**
     * The capability flags for a page of Documents, resolved in bulk.
     *
     * The actor's effective access does not vary by row, so it is resolved once
     * per capability and the record predicate asked for every Document at once —
     * the N+1 M2.6 measured on the Party reverse view and every surface since has
     * avoided by construction.
     *
     * **Two adjustments are applied per row afterwards**, both from data the row
     * already carries, so neither costs a query: a sensitive document needs
     * `documents.sensitive.view` for every write flag, and `can_download` is false
     * for every sensitive document whatever the actor holds (D-115). Status
     * eligibility is folded in too, so the interface offers `verify` only where
     * verifying would actually succeed.
     *
     * @param  Collection<int, Document>  $documents
     * @return array<string, array<string, bool>>
     */
    private function capabilityMap(Collection $documents, Request $request): array
    {
        if ($documents->isEmpty()) {
            return [];
        }

        $actor = $request->user();
        $ids = $documents->map(fn (Document $document): string => $document->getKey())->all();

        $reachable = [];

        foreach (self::CAPABILITIES as $flag => $permission) {
            $reachable[$flag] = $this->visibility->scope(
                Document::query()->whereIn('id', $ids),
                $actor,
                $this->resolver->resolve($actor, $permission),
            )->pluck('id')->flip();
        }

        $sensitivelyReachable = $this->reachableSensitively($request);

        $map = [];

        foreach ($documents as $document) {
            $key = $document->getKey();
            $sensitiveOk = ! $document->is_sensitive || $sensitivelyReachable;

            $map[$key] = [
                'can_update' => $reachable['can_update']->has($key) && $sensitiveOk,
                'can_upload' => $reachable['can_upload']->has($key) && $sensitiveOk,

                'can_verify' => $reachable['can_verify']->has($key)
                    && $sensitiveOk
                    && $document->status->isVerifiable(),

                'can_archive' => $reachable['can_archive']->has($key)
                    && $sensitiveOk
                    && $document->status->isArchivable(),

                'can_delete' => $reachable['can_delete']->has($key)
                    && $sensitiveOk
                    && $document->status->isDeletable(),

                // The D-115 gate, reported rather than hidden. False for every
                // sensitive document until an audit store exists.
                'can_download' => $reachable['can_download']->has($key) && ! $document->is_sensitive,
            ];
        }

        return $map;
    }
}
