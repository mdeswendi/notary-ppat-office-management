import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { DocumentEditForm } from "@/features/documents/document-edit-form";

/**
 * Correct a Document's metadata.
 *
 * **Not the file.** A replacement is a new version, never an edit (`CLAUDE.md`
 * section 19), and the API refuses a `file` key here. Not the status either —
 * verification and archiving each answer to their own capability and have their
 * own control on the detail page. Not the Office or the reference: both are
 * immutable.
 */
export default async function EditDocumentPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale, id } = await params;

  const t = await getTranslations({ locale, namespace: "documents" });

  return (
    <PageContainer>
      <PageHeader title={t("editTitle")} description={t("editSubtitle")} />

      <DocumentEditForm documentId={id} />
    </PageContainer>
  );
}
