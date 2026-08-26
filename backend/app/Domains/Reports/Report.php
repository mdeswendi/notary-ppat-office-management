<?php

namespace App\Domains\Reports;

use App\Policies\ReportPolicy;

/**
 * The subject {@see ReportPolicy} guards (M8.3, D-126).
 *
 * **A marker, not a model.** There is no `reports` table and there never will
 * be: every report is a read-only aggregation computed at request time, because
 * no capability in the `reports.*` family authorizes creating a stored one — all
 * six codes are `.view` plus one `.export`.
 *
 * It exists because Laravel's `Gate::policy()` maps a **class name** to a policy,
 * and controllers authorize with `$this->authorize('viewFinancial', Report::class)`.
 * Without a class to name, the alternatives were a bare `Gate::define` on a
 * permission code — which `CLAUDE.md` section 24 and D-048 forbid outright — or
 * an inline resolver call in every action, which puts authorization somewhere the
 * enforcement scan does not look.
 *
 * Nothing constructs it. It is a name.
 */
final class Report
{
    /**
     * The five report families, each answering to its own capability.
     *
     * Listed here so one place answers "which reports exist", and so the route
     * file and the Policy cannot drift apart about it.
     */
    public const OPERATIONAL = 'operational';

    public const NOTARY = 'notary';

    public const PPAT = 'ppat';

    public const FINANCIAL = 'financial';

    public const AUDIT = 'audit';

    /**
     * @return array<int, string>
     */
    public static function families(): array
    {
        return [self::OPERATIONAL, self::NOTARY, self::PPAT, self::FINANCIAL, self::AUDIT];
    }

    /**
     * The canonical capability a family answers to.
     */
    public static function capabilityFor(string $family): string
    {
        return "reports.{$family}.view";
    }
}
