<?php

namespace App\Http\Requests\Project;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for assigning a Project's person in charge (M3.3, D-097).
 *
 * **One field, and it must be present** — `pic_user_id`. `null` is a legitimate
 * value and means unassign, so the rule is `present` rather than `required`:
 * `required` would reject the very payload that clears the PIC.
 *
 * The eligibility rule is **structural, not role-based**: an existing, active,
 * non-deleted User **in the Project's own Office**. No role name is read and no
 * required role is invented — no canonical document names one, and a rule like
 * "only a notary may be PIC" would be a legal-shaped constraint from memory.
 *
 * **The same-Office rule closes a real hole.** `ASSIGNED` grants reach when
 * `project.pic_user_id == actor.id` (D-088), so naming somebody from another
 * Office as PIC would hand that person cross-office reach over a Project their
 * own scope never included — without any role changing. The restriction holds
 * even when the acting administrator holds `ALL`, because `ALL` is the *actor's*
 * reach and says nothing about who may be given work.
 *
 * Existence, activity, and Office are checked as one `Rule::exists`, so an
 * ineligible target is a **422 that does not disclose why**: a caller cannot use
 * the difference between "no such user", "disabled", and "another Office" to
 * enumerate the directory.
 */
class AssignProjectPicRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $officeId = $project instanceof Project ? $project->office_id : null;

        return [
            'pic_user_id' => [
                'present',
                'nullable',
                'string',
                Rule::exists(User::class, 'id')
                    ->where('office_id', $officeId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pic_user_id.present' => 'Send a user id to assign, or null to unassign.',
            // Deliberately one message for every ineligibility. Distinguishing
            // "not found" from "disabled" from "another Office" would make this
            // endpoint a directory probe.
            'pic_user_id.exists' => 'That user cannot be the person in charge of this Project.',
        ];
    }

    public function picUserId(): ?string
    {
        $value = $this->validated()['pic_user_id'] ?? null;

        return $value === null ? null : (string) $value;
    }
}
