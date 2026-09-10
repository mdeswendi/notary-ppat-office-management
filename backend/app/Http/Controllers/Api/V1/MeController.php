<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * The currently authenticated user.
     *
     * Route middleware guarantees a user is present. The response is wrapped
     * as `{"data": {...}}` by the resource, matching the single-resource shape
     * in docs/06_API_CONVENTIONS.md section 6.
     *
     * The Office and its Organization are loaded explicitly. `UserResource`
     * serialises them only when the relations are present — the guard every
     * other resource uses — so without this the interface would silently fall
     * back to the product's name instead of naming the deployment and location.
     */
    public function __invoke(Request $request): UserResource
    {
        return new UserResource($request->user()->loadMissing('office.organization'));
    }
}
