import { PageContainer } from "@/components/layout/page-container";
import { ProjectDetail } from "@/features/projects/project-detail";

/**
 * One Project.
 *
 * The heading lives in the client component because it renders the Project's
 * reference and title, which are only known once the record loads — and the
 * record may legitimately be unreachable: an archived Project, or one outside the
 * caller's Data Scope, answers 404 or 403 by design, and the component presents
 * each without leaking which it was.
 */
export default async function ProjectDetailPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { id } = await params;

  return (
    <PageContainer>
      <ProjectDetail projectId={id} />
    </PageContainer>
  );
}
