import { Suspense } from "react";
import { getTranslations } from "next-intl/server";

import { PageBackLink } from "@/components/layout/page-back-link";
import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { Card } from "@/components/ui/card";
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
      <PageHeader
        title={t("confirmEmailTitle")}
        description={t("confirmEmailSubtitle")}
        breadcrumb={<PageBackLink href="/security" />}
      />

      {/* `useSearchParams` needs a Suspense boundary to keep the rest of the
          route statically renderable. */}
      <Card className="max-w-xl">
        <Suspense fallback={<Skeleton className="h-20 w-full" />}>
          <ConfirmEmailPanel />
        </Suspense>
      </Card>
    </PageContainer>
  );
}
