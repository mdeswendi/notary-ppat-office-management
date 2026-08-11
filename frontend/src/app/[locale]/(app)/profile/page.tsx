import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { ProfileForm } from "@/features/profile/profile-form";

/**
 * My Profile.
 *
 * Inside the authenticated route group, so the existing server-side session
 * check applies unchanged. **No permission guards it** — every signed-in user
 * reaches their own account, and no `profile.view` permission was invented to
 * make a menu entry render (D-066).
 *
 * Deliberately not a Settings destination: Settings holds administration, and
 * this is the opposite of that. It is reached from the account menu.
 */
export default async function ProfilePage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "profile" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-muted-foreground">{t("subtitle")}</p>
      </div>

      <ProfileForm />
    </PageContainer>
  );
}
