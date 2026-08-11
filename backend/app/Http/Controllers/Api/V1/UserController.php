<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Identity\Actions\CreateUser;
use App\Domains\Identity\Actions\SetUserActivation;
use App\Domains\Identity\Actions\UpdateUser;
use App\Domains\Identity\Exceptions\CannotDisableSelf;
use App\Domains\Identity\UserVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\ManagedUserResource;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * User accounts.
 *
 * Thin (CLAUDE.md section 35): authorize, take validated input, call an action,
 * return a resource. The rules live in {@see UserPolicy} and
 * {@see UserVisibility}, where they can be read and tested without HTTP.
 *
 * Deliberately absent: password reset, role assignment, permission assignment,
 * and account deletion. Each is a separate capability owned by a later
 * milestone, and offering any of them from here would put it behind the wrong
 * permission.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly UserVisibility $visibility,
    ) {}

    /**
     * Users the caller may see.
     *
     * Visibility is applied **in the query**, not after fetching: an
     * office-scoped caller's SQL never selects another Office's rows, so the
     * pagination total counts only what they may see and no filter can widen it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $actor = $request->user();

        $query = $this->visibility->scopeForReading(
            User::query()->with('office'),
            $actor,
            $this->resolver->resolve($actor, 'users.view'),
        );

        if ($search = trim((string) $request->query('search', ''))) {
            // Grouped, so the search cannot escape the visibility constraint.
            $query->where(function ($inner) use ($search): void {
                $inner->whereLike('name', "%{$search}%")
                    ->orWhereLike('email', "%{$search}%");
            });
        }

        // A filter narrows what is already visible; it is intersected with the
        // scope constraint above rather than replacing it.
        if ($officeId = $request->query('office_id')) {
            $query->where('office_id', $officeId);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Name is not unique, so the key breaks ties and keeps paging stable.
        $query->orderBy('name')->orderBy('id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        return ManagedUserResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): JsonResponse
    {
        // The destination Office is part of the decision, so it is authorized
        // rather than merely validated: an office-scoped administrator may
        // create users, but only in their own Office.
        $this->authorize('create', [User::class, $request->validated('office_id')]);

        $user = $createUser->handle($request->validated());

        return ManagedUserResource::make($user)->response()->setStatusCode(201);
    }

    public function show(User $user): ManagedUserResource
    {
        $this->authorize('view', $user);

        return ManagedUserResource::make($user->load('office'));
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): ManagedUserResource
    {
        $this->authorize('update', $user);

        // Moving somebody to another Office is authorized against the
        // destination as well as the target, so an office-scoped administrator
        // cannot move a colleague out of their own reach.
        if ($request->has('office_id')) {
            $this->authorize('assignOffice', [User::class, $request->validated('office_id')]);
        }

        return ManagedUserResource::make($updateUser->handle($user, $request->validated()));
    }

    /**
     * Refuses with 409 when the caller is the target — see
     * {@see CannotDisableSelf}.
     */
    public function disable(Request $request, User $user, SetUserActivation $setActivation): ManagedUserResource
    {
        $this->authorize('setActivation', $user);

        return ManagedUserResource::make($setActivation->handle($request->user(), $user, false));
    }

    public function enable(Request $request, User $user, SetUserActivation $setActivation): ManagedUserResource
    {
        $this->authorize('setActivation', $user);

        return ManagedUserResource::make($setActivation->handle($request->user(), $user, true));
    }

    /**
     * Form metadata for User Management: the active Offices this caller may
     * place a user in.
     *
     * Not an Office API — it reads nothing else and writes nothing. Building a
     * general Office endpoint to fill one dropdown would be Office Management,
     * which is a different milestone.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewOptions', User::class);

        $actor = $request->user();

        // The union of both capabilities, because the form serves both: an
        // administrator who may create only in their own Office but update
        // across all of them needs every Office either grant reaches (D-028).
        $create = $this->resolver->resolve($actor, 'users.create');
        $update = $this->resolver->resolve($actor, 'users.update');

        $scopes = array_merge(
            $create->granted ? $create->scopes : [],
            $update->granted ? $update->scopes : [],
        );

        $offices = $this->visibility->assignableOfficesForScopes($actor, $scopes)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return response()->json([
            'data' => [
                'offices' => $offices->map(fn ($office): array => [
                    'id' => $office->id,
                    'code' => $office->code,
                    'name' => $office->name,
                ])->all(),
            ],
        ]);
    }
}
