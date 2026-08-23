import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { DocumentUploadForm } from "@/features/documents/document-upload-form";

/**
 * File a new Document.
 *
 * The document lands in the actor's own Office and is stamped with a
 * system-generated reference — neither is a field on this page, because neither
 * is a choice (D-116, D-117).
 */
export default async function UploadDocumentPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "documents" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("uploadTitle")}</h1>
        <p className="text-muted-foreground">{t("uploadSubtitle")}</p>
      </div>

      <DocumentUploadForm />
    </PageContainer>
  );
}
