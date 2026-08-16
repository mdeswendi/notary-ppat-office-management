<?php

namespace App\Domains\Party\Actions;

use App\Domains\Party\Enums\CompanyRelationshipCategory;
use App\Domains\Party\Enums\CompanyRelationshipType;
use App\Models\Company;
use App\Models\CompanyPerson;
use App\Models\Individual;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Record a new Company-to-Individual relationship.
 *
 * One Action for both categories rather than two near-identical ones, because
 * the category is never a *choice* here — the controller fixes it, and this
 * asserts the submitted type agrees. That assertion is the point: a single
 * implementation with an internal invariant is harder to get wrong than two
 * copies that must stay in step, and it fails loudly rather than quietly
 * writing a SHAREHOLDER row through the management surface.
 *
 * **`office_id` is copied from the Company's Party, never from input.** It is
 * the constraint carrier the composite foreign keys use to make both endpoints
 * share an Office (D-080), so a cross-office relationship is unrepresentable at
 * the database level. This action supplies the value the database will check
 * against; the check itself is PostgreSQL's, and the Form Request refuses the
 * candidate earlier so an ordinary mistake gets a 422 rather than a 500.
 *
 * **Nothing here counts anything.** No rule caps directors, requires a
 * commissioner, makes shareholdings total 100%, or infers beneficial ownership
 * from a percentage. Those are legal rules and M2 has no authority to invent
 * them (D-083).
 *
 * Adding never modifies an existing row. Superseding a relationship is
 * {@see EndCompanyRelationship} followed by this — two rows, both readable.
 */
class AddCompanyRelationship
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        User $actor,
        Company $company,
        Individual $individual,
        CompanyRelationshipCategory $category,
        array $attributes,
    ): CompanyPerson {
        $type = $attributes['relationship_type'] instanceof CompanyRelationshipType
            ? $attributes['relationship_type']
            : CompanyRelationshipType::from((string) $attributes['relationship_type']);

        // Defence in depth. The Form Request already restricts the type to this
        // category's codes, so reaching this is a programming error rather than
        // a bad request — and it must not be silently absorbed.
        if ($type->category() !== $category) {
            throw new LogicException(
                "Relationship type [{$type->value}] does not belong to the [{$category->value}] surface."
            );
        }

        return DB::transaction(function () use ($actor, $company, $individual, $attributes): CompanyPerson {
            $relationship = new CompanyPerson;

            // Not fillable, by design: the endpoints and the Office carrier are
            // the historical fact, and none may arrive through fill().
            $relationship->company_party_id = $company->party_id;
            $relationship->individual_party_id = $individual->party_id;
            $relationship->office_id = $company->party->office_id;
            $relationship->created_by = $actor->getKey();
            $relationship->updated_by = $actor->getKey();

            $relationship->fill($attributes);
            $relationship->save();

            return $relationship;
        });
    }
}
