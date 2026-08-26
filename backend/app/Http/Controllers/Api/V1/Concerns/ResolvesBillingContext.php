<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Domains\Matter\Enums\MatterDomain;
use App\Models\Matter;
use App\Models\Party;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Re-resolve a billing record's parents through their own domains (M8.2).
 *
 * **A submitted id is never trusted.** A Party, Project or Matter from another
 * Office — or one the caller may not reach — must not become the context of an
 * invoice merely because its id appeared in a request body. Each is looked up
 * again through the domain that owns it, under that domain's own `view`
 * capability and Data Scope, exactly as `DocumentController` does for document
 * relations.
 *
 * **An unreachable id is a 422, not a 404 or a 403.** The caller supplied a value
 * this record cannot use; that is a problem with the submitted field, and saying
 * so through validation keeps it beside the field it belongs to. Answering 404
 * would also disclose, by elimination, which ids exist.
 *
 * ## Matter reach is domain-specific, and both are tried
 *
 * `notary.matters.view` and `ppat.matters.view` are separate grants (D-101), and
 * a billing record may attach to either kind of Matter. The Matter's own stored
 * `domain` selects which code is asked about — the same read
 * `DocumentRelationController` performs, and one of only three places in the
 * application that does it.
 */
trait ResolvesBillingContext
{
    protected function resolveParty(Request $request, ?string $id): ?Party
    {
        if ($id === null || $id === '') {
            return null;
        }

        $actor = $request->user();

        $party = $this->parties->scope(
            Party::query()->whereKey($id),
            $actor,
            $this->resolver->resolve($actor, 'parties.view'),
        )->first();

        abort_if($party === null, 422, 'The selected client is not available.');

        return $party;
    }

    protected function resolveProject(Request $request, ?string $id): ?Project
    {
        if ($id === null || $id === '') {
            return null;
        }

        $actor = $request->user();

        $project = $this->projects->scope(
            Project::query()->whereKey($id),
            $actor,
            $this->resolver->resolve($actor, 'projects.view'),
        )->first();

        abort_if($project === null, 422, 'The selected project is not available.');

        return $project;
    }

    protected function resolveMatter(Request $request, ?string $id): ?Matter
    {
        if ($id === null || $id === '') {
            return null;
        }

        $actor = $request->user();

        // Read the stored domain first, so the right permission namespace is
        // asked about. A Matter the actor cannot reach under either code is
        // unavailable, which is what the null below means.
        $candidate = Matter::query()->whereKey($id)->first();

        if ($candidate === null) {
            abort(422, 'The selected matter is not available.');
        }

        $code = $candidate->domain === MatterDomain::NOTARY
            ? 'notary.matters.view'
            : 'ppat.matters.view';

        $actor = $request->user();

        $matter = $this->matters->scope(
            Matter::query()->whereKey($id),
            $actor,
            $this->resolver->resolve($actor, $code),
        )->first();

        abort_if($matter === null, 422, 'The selected matter is not available.');

        return $matter;
    }
}
