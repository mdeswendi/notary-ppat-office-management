import { Suspense } from "react";
import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { Skeleton } from "@/components/ui/skeleton";
import { ConfirmEmailPanel } from "@/features/security/confirm-email-panel";

/**
 * The landing page for the link sent to a new email address.
 *
 * Inside the authenticated group on purpose. The token is one half of what
 * completes the change; a signed-in session is the other, so somebody who
 * receives a forwarded link cannot use it alone (D-073).
 */
export default async function ConfirmEmailPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "security" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("confirmEmailTitle")}</h1>
        <p className="text-muted-foreground">{t("confirmEmailSubtitle")}</p>
      </div>

      {/* `useSearchParams` needs a Suspense boundary to keep the rest of the
          route statically renderable. */}
      <Suspense fallback={<Skeleton className="h-20 w-full" />}>
        <ConfirmEmailPanel />
      </Suspense>
    </PageContainer>
  );
}
