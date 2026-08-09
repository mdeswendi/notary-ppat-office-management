"use client";

import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { PageContainer } from "@/components/layout/page-container";
import { Button } from "@/components/ui/button";

/**
 * Route-level error boundary.
 *
 * The `error` object Next.js passes in is deliberately not rendered and not
 * logged to the console: it can carry server-side detail, and CLAUDE.md
 * sections 32 and 48 forbid surfacing raw exceptions to users. Only the
 * translated generic message is shown, with `reset` offered as the retry.
 */
export default function LocaleError({ reset }: { error: Error; reset: () => void }) {
  const t = useTranslations("common");
  const tActions = useTranslations("actions");

  return (
    <PageContainer>
      <BaseErrorState
        title={t("errorTitle")}
        description={t("errorDescription")}
        action={
          <Button type="button" variant="outline" onClick={reset}>
            {tActions("retry")}
          </Button>
        }
      />
    </PageContainer>
  );
}
