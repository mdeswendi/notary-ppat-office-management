<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Domains\Matter\Enums\MatterDomain;
use App\Models\Matter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * How a Matter controller learns which domain it is serving (M4.4, D-101).
 *
 * **The domain comes from the route and from nowhere else.** Each Matter route
 * stamps it as a route default — `/notary/matters` carries `NOTARY`,
 * `/ppat/matters` carries `PPAT` — and this trait reads it back off
 * `$request->route()`. It is deliberately **not** read from `$request->input()`,
 * not from a query string, and not from the record being addressed: the domain
 * selects the permission namespace, so letting a caller influence it would put
 * authorization under their control. That is the authorization shape
 * `13_M3_PROJECT_ARCHITECTURE.md` section 12 flagged and M4.0 declined.
 *
 * It is read explicitly rather than injected as a typed controller argument
 * because Laravel fills non-model parameters in **route-parameter order**, not by
 * name, so a signature like `(Request, MatterDomain, string $matter)` silently
 * receives them the other way round on any route that also has a `{matter}`
 * segment. Reading the route is order-independent and says what it means.
 */
trait ResolvesMatterDomain
{
    /**
     * The domain this route serves.
     *
     * Fails loudly rather than defaulting: a Matter route without a domain
     * default is a wiring mistake, and guessing one would pick a permission
     * namespace at random.
     */
    protected function matterDomain(Request $request): MatterDomain
    {
        $route = $request->route();
        $value = $route?->defaults['domain'] ?? null;

        if (! is_string($value) || ($domain = MatterDomain::tryFrom($value)) === null) {
            throw new RuntimeException(
                'A Matter route must declare its domain as a route default (D-101). '
                .'The domain selects the permission namespace and is never taken from the request.'
            );
        }

        return $domain;
    }

    /**
     * A Matter of **this domain**, or 404.
     *
     * The domain constraint is in the query rather than checked afterwards, and a
     * foreign-domain Matter answers 404 rather than 403: a 403 would confirm the
     * record exists in a domain the caller did not name, which is exactly what a
     * domain-split surface must not leak. The D-098 nested-binding convention,
     * applied to a domain root instead of a parent id.
     */
    protected function resolveMatter(MatterDomain $domain, string $matterId): Matter
    {
        $matter = Matter::query()
            ->where('domain', $domain->value)
            ->whereKey($matterId)
            ->first();

        if ($matter === null) {
            throw (new ModelNotFoundException)->setModel(Matter::class, [$matterId]);
        }

        return $matter;
    }
}
