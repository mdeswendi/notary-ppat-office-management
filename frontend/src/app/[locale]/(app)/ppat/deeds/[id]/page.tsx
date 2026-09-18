import { PageContainer } from "@/components/layout/page-container";
import { PageBackLink } from "@/components/layout/page-back-link";
import { PpatDeedDetail } from "@/features/ppat/deed-detail";

/**
 * One PPAT Deed (M7.2, D-121).
 *
 * The heading lives in the client component because it renders the deed's own title
 * beside its status badges, which are not known until the record loads. An unreachable
 * deed answers 404 from the API and the component shows the translated not-found
 * message — never a server string (`AGENTS.md` section 48).
 */
export default async function PpatDeedPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return (
    <PageContainer>
      <PageBackLink href="/ppat/deeds" preferHistory={false} />
      <PpatDeedDetail deedId={id} />
    </PageContainer>
  );
}
