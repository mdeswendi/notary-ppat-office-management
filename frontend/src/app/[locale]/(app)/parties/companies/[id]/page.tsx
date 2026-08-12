import { CompanyDetail } from "@/features/companies/company-detail";
import { PageContainer } from "@/components/layout/page-container";

/**
 * One Company.
 *
 * The heading lives in the client component because it renders the
 * organization's display name, which is only known once the record loads — and
 * the record may legitimately be unreachable: an Individual id, an archived
 * record, or another Office's row all answer 404 or 403 by design, and the
 * component presents each without leaking which it was.
 */
export default async function CompanyDetailPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { id } = await params;

  return (
    <PageContainer>
      <CompanyDetail companyId={id} />
    </PageContainer>
  );
}
