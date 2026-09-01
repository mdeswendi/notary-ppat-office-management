import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { DocumentsList } from "@/features/documents/documents-list";

/**
 * The Document list.
 *
 * Inside the authenticated route group, so the existing server-side session check
 * applies. `documents.view` at a usable scope is enforced by the API, which
 * remains the security boundary regardless of what navigation shows.
 *
 * **One surface, not two.** Unlike Matter there is no Notary or PPAT variant of
 * this page: `documents.*` is a single canonical namespace with no domain split,
 * so there is nothing for a route segment to select (D-101 governs Matter because
 * that catalogue is split; it has no bearing here).
 */
export default async function DocumentsPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "documents" });

  return (
    <PageContainer>
      <PageHeader title={t("title")} description={t("subtitle")} />

      <DocumentsList />
    </PageContainer>
  );
}
