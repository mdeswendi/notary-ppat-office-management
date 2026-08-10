<?php

namespace App\Http\Requests\Roles;

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for replacing a role's permission configuration.
 *
 * Every grant must name a **canonical** permission. A row that exists in the
 * table but not in the registry — which `permissions:sync` deliberately
 * preserves (D-036) — is not configurable: the resolver would refuse it anyway,
 * so offering it would let an administrator build a grant that silently does
 * nothing.
 *
 * Every grant must carry a scope the permission actually allows
 * ({@see PermissionScopeRules}). `TEAM` is rejected everywhere, because no Team
 * entity exists for it to match against (D-042).
 *
 * Duplicate codes are rejected rather than resolved by last-wins. A payload
 * listing one permission twice at two scopes is ambiguous, and guessing which
 * the administrator meant is how a configuration ends up not matching the screen
 * that produced it.
 *
 * `guard_name` is not accepted at all — the guard is fixed
 * ({@see PermissionRegistry::GUARD}).
 */
class ReplaceRolePermissionsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*.code' => ['required', 'string', Rule::in(PermissionRegistry::all())],
            'permissions.*.scope' => [
                'required',
                'string',
                // TEAM is a canonical DataScope but never an assignable one, so
                // the accepted set is narrower than the enum.
                Rule::in(array_map(
                    fn (DataScope $scope): string => $scope->value,
                    array_filter(DataScope::cases(), fn (DataScope $scope): bool => $scope !== DataScope::TEAM),
                )),
            ],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $grants = $this->input('permissions', []);

            // The `array` rule has already recorded its own error for anything
            // else; walking a string here would raise instead of validating.
            if (! is_array($grants)) {
                return;
            }

            $seen = [];
            $rules = app(PermissionScopeRules::class);

            foreach ($grants as $index => $grant) {
                if (! is_array($grant)) {
                    continue;
                }

                $code = $grant['code'] ?? null;
                $scope = $grant['scope'] ?? null;

                if (! is_string($code) || ! is_string($scope)) {
                    continue;
                }

                if (isset($seen[$code])) {
                    $validator->errors()->add(
                        "permissions.{$index}.code",
                        "The permission [{$code}] is listed more than once."
                    );

                    continue;
                }

                $seen[$code] = true;

                $parsed = DataScope::tryFrom($scope);

                if ($parsed === null || ! $rules->permits($code, $parsed)) {
                    $validator->errors()->add(
                        "permissions.{$index}.scope",
                        "The scope [{$scope}] is not allowed for [{$code}]."
                    );
                }
            }
        });
    }

    /**
     * The validated grants, with scopes as enum cases.
     *
     * @return array<int, array{code: string, scope: DataScope}>
     */
    public function grants(): array
    {
        return array_map(
            fn (array $grant): array => [
                'code' => $grant['code'],
                'scope' => DataScope::from($grant['scope']),
            ],
            $this->validated()['permissions'] ?? [],
        );
    }
}
