<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Matter\Actions\CancelMatter;
use App\Domains\Matter\Actions\CompleteMatter;
use App\Http\Controllers\Api\V1\Concerns\ResolvesMatterDomain;
use App\Http\Controllers\Controller;
use App\Http\Resources\MatterResource;
use Illuminate\Http\Request;

/**
 * The two Matter lifecycle acts M4 authorizes (M4.4, D-109).
 *
 * **Two capabilities, two endpoints, and neither implies the other.**
 * `*.matters.complete` and `*.matters.cancel` are separate canonical codes, so
 * they are separate abilities and separate routes. Neither is reachable through
 * ordinary update, which is the D-091 discipline every domain in this repository
 * follows.
 *
 * **What is deliberately missing, and why it is worth saying.** Matter has
 * `complete` and `cancel` but **no `change_status`** — Project has one, Matter
 * does not, and that asymmetry is the registry's rather than an oversight here.
 * The consequence is honest and was accepted rather than engineered around:
 * `IN_PROGRESS`, `WAITING`, `ON_HOLD` and `ARCHIVED` are canonical vocabulary
 * that **no capability in M4 can set**. A Matter reaches `OPEN` at creation,
 * `COMPLETED` through completion, and `CANCELLED` through cancellation.
 *
 * The three ways out were all refused: inventing `*.matters.change_status` would
 * change the canonical catalogue on this milestone's own authority; reusing
 * `*.matters.change_stage` would make a workflow capability do business-status
 * work it was never named for, before any workflow exists (D-104); and letting
 * ordinary update write `status` would make `update` a silent superset of the two
 * capabilities an administrator granted separately. If the office needs the other
 * statuses, that is a registry decision somebody makes deliberately.
 *
 * **Neither act deletes anything.** `COMPLETED` and `CANCELLED` are business
 * statuses, never persistence states: `deleted_at` is untouched, the internal
 * reference is kept permanently, and the Matter stays in the ordinary list
 * (D-102). M4 has no archive or restore lifecycle at all.
 *
 * **No transition matrix**, so neither act is a lock — see the Actions.
 */
class MatterLifecycleController extends Controller
{
    use ResolvesMatterDomain;

    public function complete(Request $request, string $matter, CompleteMatter $complete): MatterResource
    {
        $domain = $this->matterDomain($request);
        $record = $this->resolveMatter($domain, $matter);

        $this->authorize('complete', [$record, $domain]);

        $updated = $complete->handle($request->user(), $record);

        return new MatterResource($updated->load(['office', 'project', 'serviceType', 'picUser']));
    }

    public function cancel(Request $request, string $matter, CancelMatter $cancel): MatterResource
    {
        $domain = $this->matterDomain($request);
        $record = $this->resolveMatter($domain, $matter);

        $this->authorize('cancel', [$record, $domain]);

        $updated = $cancel->handle($request->user(), $record);

        return new MatterResource($updated->load(['office', 'project', 'serviceType', 'picUser']));
    }
}
