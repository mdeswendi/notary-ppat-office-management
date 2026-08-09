import { LoadingSkeleton } from "@/components/feedback/loading-skeleton";
import { PageContainer } from "@/components/layout/page-container";

/**
 * Route-level loading boundary. Uses the shared skeleton so loading looks the
 * same wherever it appears.
 */
export default function Loading() {
  return (
    <PageContainer>
      <LoadingSkeleton />
    </PageContainer>
  );
}
