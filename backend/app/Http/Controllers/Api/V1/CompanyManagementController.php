<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Party\Actions\AddCompanyRelationship;
use App\Domains\Party\Enums\CompanyRelationshipCategory;
use App\Http\Requests\Party\StoreCompanyManagementRequest;
use App\Http\Resources\CompanyManagementResource;
use App\Models\Company;
use Illuminate\Http\JsonResponse;

/**
 * Who acts for a Company — directors, commissioners, authorized persons.
 *
 * Answers to `companies.management.*` and nothing else. Holding
 * `companies.shareholders.view` reaches none of this, and holding
 * `companies.view` reaches none of it either: ordinary Company details and the
 * people who run the organization are separate capabilities (D-083).
 *
 * Everything but the category lives in {@see CompanyRelationshipController}. The
 * category is fixed here, by the class the route points at, so it cannot arrive
 * from a request.
 */
class CompanyManagementController extends CompanyRelationshipController
{
    protected function category(): CompanyRelationshipCategory
    {
        return CompanyRelationshipCategory::MANAGEMENT;
    }

    protected function resourceClass(): string
    {
        return CompanyManagementResource::class;
    }

    public function store(
        StoreCompanyManagementRequest $request,
        Company $company,
        AddCompanyRelationship $add,
    ): JsonResponse {
        return $this->storeRelationship(
            $request,
            $company,
            $request->relationshipAttributes(),
            $request->validated('individual_id'),
            $add,
        );
    }
}
