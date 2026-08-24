<?php

namespace App\Http\Requests\Document;

use App\Domains\Document\Enums\DocumentRelationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for attaching and detaching a Document (M5.3, D-118).
 *
 * **One class for both acts, because the payload is identical**: a relation type
 * and the id of the record on the other end. Splitting it into
 * `AttachDocumentRequest` and `DetachDocumentRequest` would be two files that must
 * be kept in step, and the first divergence between them would be a bug rather
 * than a feature.
 *
 * **`entity_type` is validated against the enum, so an unbuilt junction answers
 * 422 with a field error** rather than a 500. `property`, `notary_deed` and
 * `ppat_deed` are recommended by `03_DATABASE_ERD.md` section 14 and their tables
 * do not exist (D-115), so they are not enum cases and are refused here by name
 * like any other unknown value.
 *
 * **`entity_id` is checked for shape only.** Whether the record exists, is in the
 * caller's Office, and is reachable under that domain's own view capability is
 * decided by the controller through the domain's visibility class — an id that
 * fails any of those produces one indistinguishable 422, because telling them
 * apart would answer a question the caller has no permission to ask. This class
 * validates shape; the controller validates authority.
 */
class DocumentRelationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'string', Rule::in(DocumentRelationType::values())],

            // ULID shape, so a malformed id never reaches a query. Existence,
            // Office and reachability are the controller's to decide.
            'entity_id' => ['required', 'string', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entity_type.in' => 'A document can be attached to a party, a project, or a matter.',
        ];
    }

    public function relationType(): DocumentRelationType
    {
        return DocumentRelationType::from((string) $this->validated()['entity_type']);
    }

    public function entityId(): string
    {
        return (string) $this->validated()['entity_id'];
    }
}
