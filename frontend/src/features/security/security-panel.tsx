"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { EmailSection } from "@/features/security/email-section";
import { PasswordSection } from "@/features/security/password-section";
import { SessionsSection } from "@/features/security/sessions-section";
import { TwoFactorSection } from "@/features/security/two-factor-section";
import { toApiErrorKey } from "@/lib/api/errors";
import { getSecurityOverview, securityQueryKeys } from "@/services/security";

/**
 * Account Security.
 *
 * Four concerns on one page — password, email address, two-factor, and signed-in
 * devices — because they answer one question between them: is this account still
 * only mine? Splitting them across four screens would make that question harder
 * to answer, which is the opposite of the point.
 *
 * Self-service throughout. No permission guards any of it, and no id is ever
 * sent: the API has no route here that accepts one. Administering somebody
 * else's security is a different surface with its own canonical permissions.
 */
export function SecurityPanel() {
  const t = useTranslations("security");
  const tActions = useTranslations("actions");

  const query = useQuery({
    queryKey: securityQueryKeys.overview,
    queryFn: getSecurityOverview,
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-3" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        {[0, 1, 2].map((row) => (
          <Skeleton key={row} className="h-32 w-full" />
        ))}
      </div>
    );
  }

  if (query.isError || !query.data) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toApiErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <PasswordSection />
      <TwoFactorSection overview={query.data} />
      <EmailSection overview={query.data} />
      <SessionsSection />
    </div>
  );
}
