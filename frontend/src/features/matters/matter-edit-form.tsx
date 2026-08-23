"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { MatterForm } from "@/features/matters/matter-form";
import { toMatterErrorKey } from "@/features/matters/matter-errors";
import { getMatter, matterQueryKeys } from "@/services/matters";
import type { MatterDomain } from "@/types/matter";

/**
 * Load a Matter, then hand it to the shared form.
 *
 * Split from the form so the loading and unreachable states are handled once, and
 * so the form itself stays a pure input surface. A Matter outside the caller's
 * Data Scope answers 403 and one of the other domain answers 404 (D-101); both
 * are presented without disclosing which occurred.
 */
export function MatterEditForm({ domain, matterId }: { domain: MatterDomain; matterId: string }) {
  const t = useTranslations("matters");
  const tActions = useTranslations("actions");

  const query = useQuery({
    queryKey: matterQueryKeys.detail(domain, matterId),
    queryFn: () => getMatter(domain, matterId),
  });

  if (query.isPending) {
    return (
      <div className="flex max-w-2xl flex-col gap-3" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-10 w-full" />
      </div>
    );
  }

  if (query.isError || !query.data) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toMatterErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  return <MatterForm domain={domain} matter={query.data} />;
}
