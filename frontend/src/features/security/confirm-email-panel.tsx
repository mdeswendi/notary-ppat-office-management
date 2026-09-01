"use client";

import { useEffect, useRef, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";

import { ButtonLink } from "@/components/ui/button-link";
import { Skeleton } from "@/components/ui/skeleton";
import { SecurityError, SecurityNotice } from "@/features/security/security-section";
import { toApiErrorKey } from "@/lib/api/errors";
import { authQueryKeys } from "@/services/auth";
import { profileQueryKeys } from "@/services/profile";
import { securityQueryKeys, verifyEmailChange } from "@/services/security";

/**
 * Where the verification link in the new mailbox lands.
 *
 * The token arrives in the query string and is submitted once, on mount. It is
 * never stored, never logged, and never rendered — the address that changed is
 * what the person needs to see, not the token that changed it.
 *
 * Authenticated, deliberately. The link alone is not enough: completing the
 * change needs the token *and* a signed-in session, so a forwarded email cannot
 * move somebody's account on its own (D-073).
 */
export function ConfirmEmailPanel() {
  const t = useTranslations("security");
  const searchParams = useSearchParams();
  const queryClient = useQueryClient();

  const token = searchParams.get("token") ?? "";
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => verifyEmailChange(token),
    onSuccess: async (overview) => {
      queryClient.setQueryData(securityQueryKeys.overview, overview);

      // The address appears in the header and on the profile page, so both are
      // refetched rather than left showing the old one.
      await queryClient.invalidateQueries({ queryKey: profileQueryKeys.profile });
      await queryClient.invalidateQueries({ queryKey: authQueryKeys.me });
    },
    onError: (error: unknown) => setErrorKey(toApiErrorKey(error)),
  });

  // Submitted exactly once. React runs effects twice in development, and a
  // second submission of a single-use token would report a failure for a change
  // that had just succeeded.
  const submitted = useRef(false);
  const { mutate } = mutation;

  useEffect(() => {
    if (submitted.current || token === "") {
      return;
    }

    submitted.current = true;
    mutate();
  }, [mutate, token]);

  if (token === "") {
    return <SecurityError>{t("confirmEmailMissingToken")}</SecurityError>;
  }

  if (mutation.isPending || mutation.isIdle) {
    return (
      <div aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("confirmEmailWorking")}</span>
        <Skeleton className="h-20 w-full" />
      </div>
    );
  }

  if (mutation.isError) {
    return (
      <div className="flex flex-col items-start gap-4">
        <SecurityError>{t(`errors.${errorKey ?? "server"}`)}</SecurityError>
        <p className="text-muted-foreground text-sm">{t("confirmEmailFailedHint")}</p>
        <ButtonLink href="/security" variant="outline">
          {t("backToSecurity")}
        </ButtonLink>
      </div>
    );
  }

  return (
    <div className="flex flex-col items-start gap-4">
      <SecurityNotice>{t("confirmEmailSuccess", { email: mutation.data.email })}</SecurityNotice>
      <p className="text-muted-foreground text-sm">{t("confirmEmailSessionsRevoked")}</p>
      <ButtonLink href="/security">{t("backToSecurity")}</ButtonLink>
    </div>
  );
}
