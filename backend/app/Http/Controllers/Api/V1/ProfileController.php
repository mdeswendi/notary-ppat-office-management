<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Identity\Actions\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\Request;

/**
 * The authenticated user's own account.
 *
 * **Self-service, not administration.** Every authenticated user reaches their
 * own profile, and no canonical permission guards it — there is no
 * `profile.view` in the registry and M1.8 does not invent one (D-066).
 * Authentication plus self-ownership is the whole boundary.
 *
 * The target is always `$request->user()`. There is no `/profile/{user}`, no id
 * parameter, and no way to name somebody else: administrative access to another
 * person's record is M1.5's `users.*` capabilities, deliberately kept separate.
 *
 * Not routed through `UserPolicy` either. That policy excludes the `OWN` scope
 * from administrative update on purpose (D-049); bending it to fit self-service
 * would weaken the rule it exists to state.
 */
class ProfileController extends Controller
{
    public function show(Request $request): ProfileResource
    {
        return ProfileResource::make($request->user()->load('office'));
    }

    public function update(UpdateProfileRequest $request, UpdateProfile $updateProfile): ProfileResource
    {
        return ProfileResource::make(
            $updateProfile->handle($request->user(), $request->validated())
        );
    }
}
