import { PageContainer } from "@/components/layout/page-container";
import { DocumentDetail } from "@/features/documents/document-detail";

/**
 * One Document.
 *
 * The heading lives in the client component because it renders the reference and
 * title, which are only known once the record loads — and the record may
 * legitimately be unreachable: one outside the caller's Data Scope answers **404**
 * rather than 403, because telling an unreachable document apart from a
 * nonexistent one would confirm the record exists somewhere the caller may not
 * look. The component presents each without leaking which it was.
 */
export default async function DocumentDetailPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { id } = await params;

  return (
    <PageContainer>
      <DocumentDetail documentId={id} />
    </PageContainer>
  );
}
