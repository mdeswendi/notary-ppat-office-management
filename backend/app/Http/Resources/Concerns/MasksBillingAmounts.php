<?php

namespace App\Http\Resources\Concerns;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Billing\BillingVisibility;
use Illuminate\Http\Request;

/**
 * Withhold money from an actor who may not see it (M8.2, D-125).
 *
 * `billing.amount.view` is a **separate capability** from `billing.view` and from
 * every entity's own `*.view`. The catalogue does not explain why, but its shape
 * matches `CLAUDE.md` section 22, where reading a record and reading its
 * protected values are distinct grants — the pattern NIK and NPWP already
 * follow.
 *
 * ## Absent, never present-and-hidden
 *
 * A masked amount **is not in the payload at all**. It is not `null`, not
 * `"***"`, and not a zero: the key is gone. Sending a value the client is
 * expected to hide is not masking — it is a disclosure with a request attached,
 * and one `curl` reads it. This is the same rule that governs NIK: the raw value
 * exists in exactly one response, the explicit reveal, and nowhere else.
 *
 * `amounts_visible` ships as a flag so an interface can render a placeholder
 * deliberately rather than inferring one from a missing key.
 *
 * ## Resolved once per request, not once per row
 *
 * The grant does not vary between rows, so the controller resolves it once and
 * stashes it on the request. Resolving inside `toArray()` would put an
 * `EffectiveAccessResolver` call on every row of every page — the N+1 every
 * surface since M2.6 has avoided by construction.
 *
 * ## It fails closed
 *
 * {@see self::amountsVisible()} defaults to **false** when the flag was never
 * set. A controller that forgets to call {@see self::resolveAmountVisibility()}
 * therefore withholds money rather than disclosing it, which is the only safe
 * direction for a default nobody remembered to set.
 */
trait MasksBillingAmounts
{
    /**
     * The request attribute the resolved grant is stashed under.
     */
    private const FLAG = 'billing.amounts_visible';

    /**
     * Resolve the grant once and remember it for this request.
     *
     * Called by the controller before serialising anything.
     */
    public static function resolveAmountVisibility(Request $request): bool
    {
        $actor = $request->user();

        if ($actor === null) {
            return false;
        }

        $visible = app(BillingVisibility::class)->hasUsableScope(
            app(EffectiveAccessResolver::class)->resolve($actor, 'billing.amount.view')
        );

        $request->attributes->set(self::FLAG, $visible);

        // **Also on the container's request, and this is not belt-and-braces.**
        // A controller usually passes its `FormRequest`, which Laravel builds as
        // a *separate object* from the request a Resource is handed — so setting
        // the flag on one leaves the other unset, and the trait's fail-closed
        // default then withholds money from somebody entitled to see it. Caught
        // by a test asserting a total that came back absent for an actor holding
        // `billing.amount.view`.
        $container = app('request');

        if ($container !== $request) {
            $container->attributes->set(self::FLAG, $visible);
        }

        return $visible;
    }

    /**
     * May this caller see monetary figures?
     *
     * **Defaults to false.** See the trait docblock.
     */
    protected function amountsVisible(Request $request): bool
    {
        return $request->attributes->getBoolean(self::FLAG, false);
    }

    /**
     * Merge monetary keys into a payload only when they may be seen.
     *
     * @param  array<string, mixed>  $amounts
     * @return array<string, mixed>
     */
    protected function withAmounts(Request $request, array $amounts): array
    {
        if (! $this->amountsVisible($request)) {
            return [];
        }

        return $amounts;
    }
}
