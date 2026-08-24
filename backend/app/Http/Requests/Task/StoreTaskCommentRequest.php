<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validation for a remark on a Task (M5.4, D-119).
 *
 * One field. `user_id` is `prohibited` and comes from the session instead: a
 * fillable author would let a caller sign somebody else's name, which is the one
 * thing a comment must never allow.
 *
 * **A maximum length, because `TEXT` has none.** Without a bound a single comment
 * could carry megabytes into a list endpoint that renders every remark on a task.
 * 5000 characters is a paragraph of paragraphs — long enough that nobody meets it
 * writing an explanation, short enough that a page of comments stays a page.
 */
class StoreTaskCommentRequest extends FormRequest
{
    private const FORBIDDEN = ['id', 'task_id', 'user_id', 'deleted_at'];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'comment' => ['required', 'string', 'max:5000'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

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
            'user_id.prohibited' => 'A comment is signed by the person sending it.',
            'task_id.prohibited' => 'The task comes from the address you are posting to.',
        ];
    }

    public function comment(): string
    {
        return trim((string) $this->validated()['comment']);
    }
}
