import { IndividualDetail } from "@/features/individuals/individual-detail";
import { PageContainer } from "@/components/layout/page-container";

/**
 * One Individual.
 *
 * The heading lives in the client component because it renders the person's
 * name, which is only known once the record loads — and the record may legitimately
 * be unreachable: a Company id, an archived record, or another Office's row all
 * answer 404 or 403 by design, and the component presents each without leaking
 * which it was.
 */
export default async function IndividualDetailPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { id } = await params;

  return (
    <PageContainer>
      <IndividualDetail individualId={id} />
    </PageContainer>
  );
}
