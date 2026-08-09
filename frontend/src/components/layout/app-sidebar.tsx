import { getTranslations } from "next-intl/server";

import { SidebarNav } from "@/components/layout/sidebar-nav";
import { Separator } from "@/components/ui/separator";
import type { CurrentUser } from "@/types/auth";

/**
 * Desktop sidebar.
 *
 * Width follows docs/04_UI_DESIGN_SYSTEM.md section 3 (240-260px). Hidden
 * below `lg`, where MobileNav takes over with the same navigation data.
 *
 * Collapse to a 72px icon rail is described in section 3 but is deliberately
 * not built at M0.9 — with Dashboard as the only destination it would be a
 * toggle plus tooltip machinery around a single row. See the open item.
 */
export async function AppSidebar({ user }: { user: CurrentUser }) {
  const t = await getTranslations("navigation");
  const tCommon = await getTranslations("common");

  return (
    <aside className="bg-sidebar border-sidebar-border hidden w-64 shrink-0 flex-col border-r lg:flex">
      <nav aria-label={t("mainLabel")} className="flex-1 p-3">
        <SidebarNav user={user} />
      </nav>

      <div className="p-3">
        <Separator />
        <p className="text-muted-foreground px-3 pt-3 text-xs">{tCommon("officeLabel")}</p>
      </div>
    </aside>
  );
}
