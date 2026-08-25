import { PageContainer } from "@/components/layout/page-container";
import { PropertyDetail } from "@/features/properties/property-detail";

/**
 * One land object (M7.3, D-121).
 *
 * The heading lives in the client component because it renders the parcel's certificate
 * number beside its badges, which are not known until the record loads. An unreachable
 * Property answers 404 from the API and the component shows the translated not-found
 * message — never a server string (`CLAUDE.md` section 48).
 *
 * **An archived parcel opens normally**, read-only. Retiring a record from the active
 * list is not making it unfindable: an office looking up an old certificate needs it,
 * and the Policy refuses only the acts that would change it.
 */
export default async function PropertyPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return (
    <PageContainer>
      <PropertyDetail propertyId={id} />
    </PageContainer>
  );
}
