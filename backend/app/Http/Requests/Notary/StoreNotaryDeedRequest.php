<?php

namespace App\Http\Requests\Notary;

use App\Policies\NotaryDeedPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for recording a Notarial Deed (M6.2, D-120).
 *
 * `matter_id` and `title` are the only required fields, and both are required
 * **structurally**: a deed is always the output of a Matter, and one with no name is
 * unusable in a list.
 *
 * ## Three fields the brief wanted required, and why they are not
 *
 * **`deed_number` is not merely optional — it is `prohibited`.** It answers to
 * `notary.deeds.number` on its own endpoint (D-120), so accepting it here would let
 * `notary.deeds.create` silently perform an act a separate capability was granted to
 * control. That is the reason `pic_user_id` is refused when creating a Matter.
 *
 * **`deed_date` is optional.** It is the date the deed was executed, and a deed being
 * drafted has not been executed — requiring it would force somebody to type a date
 * that is not yet true.
 *
 * **`deed_type_code` is optional and opaque.** The ERD gives it no vocabulary and M6
 * seeds no catalogue; requiring it would make deeds uncreatable for as long as the
 * catalogue is empty, which is the argument D-102 made for `matters.service_type_id`.
 *
 * **Every system-controlled field is `prohibited`, not silently dropped**, and the
 * refusal keys on **presence** rather than emptiness — the D-097 pattern. An
 * interface that appears to accept `status` and then ignores it is worse than one
 * that refuses, because the caller believes the deed went somewhere it did not.
 *
 * The parent Matter's eligibility — reachable under `notary.matters.view`, of the
 * NOTARY domain, and in the actor's own Office — is decided by
 * {@see NotaryDeedPolicy}, not here. This class validates shape; the Policy validates
 * authority.
 */
class StoreNotaryDeedRequest extends FormRequest
{
    /**
     * System-controlled or separately-capable, refused outright.
     */
    private const FORBIDDEN = [
        'id',
        'office_id',
        'status',
        'deed_number',
        'reviewed_at',
        'reviewed_by',
        'approved_at',
        'approved_by',
        'finalized_at',
        'finalized_by',
        'locked_at',
        'locked_by',
        'deleted_at',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'matter_id' => ['required', 'string', 'size:26'],
            'title' => ['required', 'string', 'max:255'],
            'deed_date' => ['nullable', 'date'],
            'deed_type_code' => ['nullable', 'string', 'max:50'],

            // Optional at creation. Each is re-checked against the deed's Office by
            // the composite foreign key, so a document from elsewhere is refused by
            // the database even if validation passed.
            'draft_document_id' => ['nullable', 'string', 'size:26'],
            'final_document_id' => ['nullable', 'string', 'size:26'],
            'minuta_document_id' => ['nullable', 'string', 'size:26'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function matterId(): string
    {
        return (string) $this->validated('matter_id');
    }

    /**
     * The deed's own fields, without the parent key the Policy resolves separately.
     *
     * @return array<string, mixed>
     */
    public function deedAttributes(): array
    {
        return collect($this->validated())
            ->only(['title', 'deed_date', 'deed_type_code', 'draft_document_id', 'final_document_id', 'minuta_document_id'])
            ->all();
    }
}
