<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Office\Actions\UpdateOfficePractice;
use App\Domains\Professional\Actions\AddProfessionalAppointment;
use App\Domains\Professional\Actions\EndProfessionalAppointment;
use App\Domains\Professional\Enums\ProfessionalRelationshipType;
use App\Domains\Professional\Enums\ProfessionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Office\EndProfessionalAppointmentRequest;
use App\Http\Requests\Office\StoreProfessionalAppointmentRequest;
use App\Http\Requests\Office\UpdateOfficePracticeRequest;
use App\Http\Resources\Office\OfficePracticeResource;
use App\Http\Resources\Office\ProfessionalAppointmentResource;
use App\Models\Individual;
use App\Models\Office;
use App\Models\ProfessionalAppointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfficePracticeController extends Controller
{
    public function show(Request $request): OfficePracticeResource
    {
        $office = $this->currentOffice($request);
        $this->authorize('view', $office);

        return new OfficePracticeResource($this->loadPractice($office));
    }

    public function update(
        UpdateOfficePracticeRequest $request,
        UpdateOfficePractice $update,
    ): OfficePracticeResource {
        $office = $this->currentOffice($request);
        $this->authorize('update', $office);

        return new OfficePracticeResource($this->loadPractice(
            $update->handle($office, $request->validated())
        ));
    }

    public function options(Request $request): JsonResponse
    {
        $office = $this->currentOffice($request);
        $this->authorize('update', $office);

        $individuals = Individual::query()
            ->whereHas('party', fn ($query) => $query->where('office_id', $office->getKey()))
            ->with(['party:id,display_name'])
            ->orderBy('full_name')
            ->limit(100)
            ->get()
            ->map(fn (Individual $individual): array => [
                'id' => $individual->party_id,
                'display_name' => $individual->party?->display_name,
            ])->all();

        return response()->json(['data' => [
            'individuals' => $individuals,
            'profession_types' => ProfessionType::values(),
            'relationship_types' => ProfessionalRelationshipType::values(),
        ]]);
    }

    public function storeProfessional(
        StoreProfessionalAppointmentRequest $request,
        AddProfessionalAppointment $add,
    ): JsonResponse {
        $office = $this->currentOffice($request);
        $this->authorize('update', $office);

        $individual = Individual::query()
            ->whereKey($request->validated('individual_id'))
            ->whereHas('party', fn ($query) => $query->where('office_id', $office->getKey()))
            ->with('party')
            ->first();

        if ($individual === null || $individual->party === null) {
            abort(422, 'Select a person from this office.');
        }

        $appointment = $add->handle(
            $office,
            $individual->party,
            ProfessionType::from($request->validated('profession_type')),
            ProfessionalRelationshipType::from($request->validated('relationship_type')),
            $request->appointmentAttributes(),
        );

        return ProfessionalAppointmentResource::make($appointment)->response()->setStatusCode(201);
    }

    public function endProfessional(
        EndProfessionalAppointmentRequest $request,
        string $appointment,
        EndProfessionalAppointment $end,
    ): JsonResource {
        $office = $this->currentOffice($request);
        $this->authorize('update', $office);

        $record = ProfessionalAppointment::query()
            ->whereKey($appointment)
            ->where('office_id', $office->getKey())
            ->firstOrFail();

        return ProfessionalAppointmentResource::make(
            $end->handle($record, $request->validated('ended_at'))
        );
    }

    private function currentOffice(Request $request): Office
    {
        return Office::query()->findOrFail($request->user()->office_id);
    }

    private function loadPractice(Office $office): Office
    {
        return $office->load(['professionalAppointments' => fn ($query) => $query
            ->with(['party' => fn ($party) => $party->withTrashed()])
            ->orderByDesc('is_active')->orderByDesc('appointed_at')->orderBy('id')]);
    }
}
