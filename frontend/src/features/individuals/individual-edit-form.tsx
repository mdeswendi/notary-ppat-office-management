"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { IndividualForm } from "@/features/individuals/individual-form";
import { toIndividualErrorKey } from "@/features/individuals/individual-errors";
import { getIndividual, individualQueryKeys } from "@/services/individuals";

/**
 * Loads the record, then hands it to the shared form.
 *
 * A thin wrapper so `IndividualForm` stays a pure form and does not have to know
 * whether it is creating or fetching. It also means the edit route surfaces a
 * 403 or 404 as an ordinary state — a record in another Office, or an archived
 * one, is legitimately unreachable and should not read as a crash.
 */
export function IndividualEditForm({ individualId }: { individualId: string }) {
  const t = useTranslations("individuals");
  const tActions = useTranslations("actions");

  const query = useQuery({
    queryKey: individualQueryKeys.detail(individualId),
    queryFn: () => getIndividual(individualId),
  });

  if (query.isPending) {
    return <Skeleton className="h-96 w-full" />;
  }

  if (query.isError || !query.data) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toIndividualErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  return <IndividualForm individual={query.data} />;
}
