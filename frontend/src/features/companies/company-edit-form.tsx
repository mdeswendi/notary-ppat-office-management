"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { CompanyForm } from "@/features/companies/company-form";
import { toCompanyErrorKey } from "@/features/companies/company-errors";
import { companyQueryKeys, getCompany } from "@/services/companies";

/**
 * Loads the record, then hands it to the shared form.
 *
 * A thin wrapper so `CompanyForm` stays a pure form and does not have to know
 * whether it is creating or fetching. It also means the edit route surfaces a
 * 403 or 404 as an ordinary state — a record in another Office, an Individual
 * id, or an archived one is legitimately unreachable and should not read as a
 * crash.
 */
export function CompanyEditForm({ companyId }: { companyId: string }) {
  const t = useTranslations("companies");
  const tActions = useTranslations("actions");

  const query = useQuery({
    queryKey: companyQueryKeys.detail(companyId),
    queryFn: () => getCompany(companyId),
  });

  if (query.isPending) {
    return <Skeleton className="h-96 w-full" />;
  }

  if (query.isError || !query.data) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toCompanyErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  return <CompanyForm company={query.data} />;
}
