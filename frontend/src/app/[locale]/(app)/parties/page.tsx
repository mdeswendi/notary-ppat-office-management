import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PartyDirectory } from "@/features/parties/party-directory";

/**
 * The unified Party Directory (M2.5).
 *
 * `12_M2_PARTY_ARCHITECTURE.md` section 18 originally recorded that no
 * `/[locale]/parties` page would exist, on the grounds that it would only link
 * to the two pages beside it in the sidebar. That was right while the two
 * subtype directories were all there was; it stopped being right when
 * `GET /api/v1/parties` landed, because this page now answers a question neither
 * subtype list can — *find this person or organization, whichever they are* —
 * against one backend query. The document is updated rather than contradicted.
 *
 * **It does not replace the subtype directories.** `/parties/individuals` and
 * `/parties/companies` remain the lifecycle surfaces: creating, editing, and
 * archiving stay there, under their own permissions. This page reads.
 *
 * Inside the authenticated route group, so the existing server-side session
 * check applies. The API refuses a caller who holds neither `parties.view` nor
 * `companies.view` at a usable scope, and remains the security boundary
 * regardless of what navigation shows.
 */
export default async function PartyDirectoryPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "parties" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-muted-foreground">{t("subtitle")}</p>
      </div>

      <PartyDirectory />
    </PageContainer>
  );
}
