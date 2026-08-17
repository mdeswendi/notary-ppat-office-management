import { PageContainer } from "@/components/layout/page-container";
import { MatterDetail } from "@/features/matters/matter-detail";

/**
 * One Notary Matter.
 *
 * The heading lives in the client component because it renders the reference and
 * title, which are only known once the record loads — and the record may
 * legitimately be unreachable: one outside the caller's Data Scope answers 403,
 * and **a PPAT Matter answers 404 at this address** by design (D-101). The
 * component presents each without leaking which it was.
 */
export default async function NotaryMatterDetailPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { id } = await params;

  return (
    <PageContainer>
      <MatterDetail domain="NOTARY" matterId={id} />
    </PageContainer>
  );
}
