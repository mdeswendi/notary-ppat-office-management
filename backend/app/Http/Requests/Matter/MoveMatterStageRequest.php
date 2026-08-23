<?php

namespace App\Http\Requests\Matter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validation for moving a Matter to another stage (M4.7, D-112).
 *
 * **Shape only.** `target_stage_code` is bounded and required and nothing more:
 * whether the stage exists, belongs to this Matter's workflow, and is open is
 * decided by `MoveMatterStage` against the running instance, because a Form
 * Request cannot see which workflow this Matter is on. Validating it here as well
 * would put the same rule in two places, and the two would eventually disagree.
 * (Plain text rather than an `@see` tag: Pint's `fully_qualified_strict_types`
 * turns a tagged reference into a real import, and a Form Request depending on an
 * Action to satisfy a comment is a dependency nothing asked for.)
 *
 * **No transition rule is checked anywhere** (D-104). M4 authorizes who may
 * change a stage and never encodes which stage may follow which.
 *
 * **`reason` is free text and a leak surface.** D-105 forbids persisting Party
 * identity in a field like this. Bounded at 255 to keep it a note rather than a
 * document; nothing automated can enforce what goes in it.
 *
 * The refusal keys on **presence**, not emptiness, following D-097.
 */
class MoveMatterStageRequest extends FormRequest
{
    /**
     * System-controlled, or belonging to a milestone that does not exist.
     *
     * `matter_id` and the domain come from the route. `status`, `started_at`,
     * `completed_at` and the sequence are decided by the action — a caller who
     * could set them would be writing the workflow's state directly rather than
     * moving through it.
     *
     * `assigned_user_id`, `approved_at` and `approved_by` are refused because
     * **M4.7 ships no stage assignment and no approval act**. Accepting and
     * dropping them would teach a caller that the fields work.
     */
    private const FORBIDDEN = [
        'id',
        'matter_id',
        'domain',
        'matter_workflow_id',
        'workflow_stage_id',
        'workflow_template_id',
        'status',
        'sequence_no',
        'started_at',
        'completed_at',
        'assigned_user_id',
        'approved_at',
        'approved_by',
        'changed_by',
        'changed_at',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'target_stage_code' => ['required', 'string', 'max:50'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Refuse a system-controlled key that is *present*, whatever its value
     * (D-097).
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (self::FORBIDDEN as $field) {
                if (! $this->has($field) || $validator->errors()->has($field)) {
                    continue;
                }

                $validator->errors()->add($field, $this->messageFor($field));
            }
        });
    }

    private function messageFor(string $field): string
    {
        return $this->messages()[$field.'.prohibited']
            ?? trans('validation.prohibited', ['attribute' => str_replace('_', ' ', $field)]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'matter_id.prohibited' => 'The Matter comes from the address, not the body.',
            'domain.prohibited' => 'The domain comes from the address, not the body.',
            'status.prohibited' => 'A stage status is a result of moving, not an input.',
            'assigned_user_id.prohibited' => 'Stage assignment is not built yet.',
            'approved_at.prohibited' => 'Stage approval is not built yet.',
            'approved_by.prohibited' => 'Stage approval is not built yet.',
        ];
    }
}
