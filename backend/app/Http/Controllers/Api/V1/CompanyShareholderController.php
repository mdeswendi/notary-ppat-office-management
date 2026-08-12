<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Party\Actions\AddCompanyRelationship;
use App\Domains\Party\Enums\CompanyRelationshipCategory;
use App\Http\Requests\Party\StoreCompanyShareholderRequest;
use App\Http\Resources\CompanyShareholderResource;
use App\Models\Company;
use Illuminate\Http\JsonResponse;

/**
 * Who owns a Company — shareholders and beneficial owners.
 *
 * Answers to `companies.shareholders.*` and nothing else. Ownership data is not
 * visible merely because somebody may view ordinary Company details, and it is
 * not visible to a holder of `companies.management.view` either: who runs an
 * organization and who owns it are different questions with different
 * permissions (D-083).
 *
 * Everything but the category lives in {@see CompanyRelationshipController}.
 */
class CompanyShareholderController extends CompanyRelationshipController
{
    protected function category(): CompanyRelationshipCategory
    {
        return CompanyRelationshipCategory::OWNERSHIP;
    }

    protected function resourceClass(): string
    {
        return CompanyShareholderResource::class;
    }

    public function store(
        StoreCompanyShareholderRequest $request,
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
