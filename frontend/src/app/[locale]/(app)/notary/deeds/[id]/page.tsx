import { PageContainer } from "@/components/layout/page-container";
import { DeedDetail } from "@/features/notary/deed-detail";

/**
 * One Notarial Deed (M6.2, D-120).
 *
 * The heading lives in the client component because it renders the deed's own title
 * beside its status badges, which are not known until the record loads. An
 * unreachable deed answers 404 from the API and the component shows the translated
 * not-found message — never a server string (`CLAUDE.md` section 48).
 */
export default async function NotaryDeedPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return (
    <PageContainer>
      <DeedDetail deedId={id} />
    </PageContainer>
  );
}
