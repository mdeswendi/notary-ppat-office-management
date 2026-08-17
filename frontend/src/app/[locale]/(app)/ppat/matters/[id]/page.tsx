import { PageContainer } from "@/components/layout/page-container";
import { MatterDetail } from "@/features/matters/matter-detail";

/**
 * One PPAT Matter.
 *
 * A Notary Matter answers 404 at this address, and vice versa (D-101).
 */
export default async function PpatMatterDetailPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { id } = await params;

  return (
    <PageContainer>
      <MatterDetail domain="PPAT" matterId={id} />
    </PageContainer>
  );
}
