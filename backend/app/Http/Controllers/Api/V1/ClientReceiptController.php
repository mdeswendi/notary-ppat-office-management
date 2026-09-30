<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Billing\Actions\RecordClientReceipt;
use App\Domains\Billing\Actions\UpdateClientReceipt;
use App\Domains\Billing\BillingVisibility;
use App\Domains\Party\PartyVisibility;
use App\Http\Controllers\Api\V1\Concerns\ResolvesBillingContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreClientReceiptRequest;
use App\Http\Requests\Billing\UpdateClientReceiptRequest;
use App\Http\Resources\ClientReceiptResource;
use App\Models\ClientReceipt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Actual client receipts, independent of quotations and invoices (D-139). */
class ClientReceiptController extends Controller
{
    use ResolvesBillingContext;

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly BillingVisibility $visibility,
        private readonly PartyVisibility $parties,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ClientReceipt::class);
        ClientReceiptResource::resolveAmountVisibility($request);

        $actor = $request->user();
        $query = $this->visibility->scope(
            ClientReceipt::query(),
            $actor,
            $this->resolver->resolve($actor, 'client_receipts.view'),
        )->with(['clientParty:id,display_name', 'createdBy:id,name']);

        $this->applyFilters($query, $request);
        $page = $query->orderByDesc('received_on')
            ->orderByDesc('created_at')
            ->paginate(max(1, min((int) $request->query('per_page', '25'), 100)))
            ->withQueryString();

        return ClientReceiptResource::collection($page);
    }

    public function store(
        StoreClientReceiptRequest $request,
        RecordClientReceipt $record,
    ): JsonResponse {
        $actor = $request->user();
        $this->authorize('create', [ClientReceipt::class, $actor->office_id]);
        ClientReceiptResource::resolveAmountVisibility($request);

        $client = $this->resolveParty($request, $request->validated('client_party_id'));
        abort_if($client === null, 422, 'The selected client is not available.');

        $receipt = $record->handle($actor, $client, $request->receiptAttributes());

        return (new ClientReceiptResource($this->loadForDetail($receipt)))
            ->withCapabilities($this->capabilitiesFor($receipt))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $receipt): ClientReceiptResource
    {
        $record = $this->resolveReceipt($request, $receipt);
        $this->authorize('view', $record);
        ClientReceiptResource::resolveAmountVisibility($request);

        return (new ClientReceiptResource($this->loadForDetail($record)))
            ->withCapabilities($this->capabilitiesFor($record));
    }

    public function update(
        UpdateClientReceiptRequest $request,
        string $receipt,
        UpdateClientReceipt $update,
    ): ClientReceiptResource {
        $record = $this->resolveReceipt($request, $receipt);
        $this->authorize('update', $record);
        ClientReceiptResource::resolveAmountVisibility($request);

        $updated = $update->handle($request->user(), $record, $request->receiptAttributes());

        return (new ClientReceiptResource($this->loadForDetail($updated)))
            ->withCapabilities($this->capabilitiesFor($updated));
    }

    private function resolveReceipt(Request $request, string $id): ClientReceipt
    {
        $actor = $request->user();
        $record = $this->visibility->scope(
            ClientReceipt::query()->whereKey($id),
            $actor,
            $this->resolver->resolve($actor, 'client_receipts.view'),
        )->first();

        abort_if($record === null, 404);

        return $record;
    }

    private function loadForDetail(ClientReceipt $receipt): ClientReceipt
    {
        return $receipt->load(['clientParty:id,display_name', 'createdBy:id,name']);
    }

    /** @return array<string, bool> */
    private function capabilitiesFor(ClientReceipt $receipt): array
    {
        return ['can_update' => request()->user()->can('update', $receipt)];
    }

    /** @param Builder<ClientReceipt> $query */
    private function applyFilters(Builder $query, Request $request): void
    {
        foreach (['client_party_id', 'method_code'] as $column) {
            $value = $request->query($column);
            if (is_string($value) && $value !== '') {
                $query->where($column, $value);
            }
        }

        foreach (['from' => '>=', 'until' => '<='] as $parameter => $operator) {
            $value = $request->query($parameter);
            if (is_string($value) && $value !== '') {
                $query->whereDate('received_on', $operator, $value);
            }
        }

        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $query->whereHas('clientParty', fn (Builder $partyQuery) => $partyQuery->where('display_name', 'like', "%{$search}%"));
        }
    }
}
