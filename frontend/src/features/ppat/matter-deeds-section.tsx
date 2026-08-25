"use client";

import { Plus } from "lucide-react";
import { useTranslations } from "next-intl";

import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { PpatDeedsList } from "@/features/ppat/deeds-list";
import { Link } from "@/i18n/navigation";

/**
 * The PPAT Deeds produced by one Matter (M7.2, D-121).
 *
 * **A section, not a tab**, following the ones already on the Matter page and the
 * ruling M5.2, M5.3, M5.4 and M6.2 each made: the repository has no `Tabs` primitive,
 * and adding one is a design decision affecting pages M4 already shipped rather than a
 * side effect of a PPAT milestone.
 *
 * **It asks its own endpoint**, and that is the point rather than an implementation
 * detail. Deeds answer to `ppat.deeds.view` with their own Data Scope, which is a
 * separate question from reaching this Matter — reaching a Matter confers no Deed
 * authority (D-100, restated one level down at D-121). Folding a deed collection into
 * the Matter payload would have made `ppat.matters.view` a way to read what the office
 * has drawn up, so the list renders its own honest failure for a reader who holds one
 * capability and not the other.
 *
 * **Rendered only on PPAT Matters.** A Notary Matter has no PPAT deeds, and the
 * endpoint would correctly return nothing — but an empty section headed "PPAT Deeds"
 * on a Notary page would suggest the office had failed to draw one up.
 */
export function PpatMatterDeedsSection({ matterId }: { matterId: string }) {
  const t = useTranslations("ppat");

  return (
    <>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h3 className="text-sm font-medium">{t("matterDeedsTitle")}</h3>
          <p className="text-muted-foreground text-xs">{t("matterDeedsHint")}</p>
        </div>

        <PermissionGuard permission="ppat.deeds.create">
          <Button
            variant="outline"
            size="sm"
            className="gap-2"
            render={<Link href={`/ppat/deeds/new?matter_id=${matterId}`} />}
          >
            <Plus aria-hidden="true" />
            {t("newDeed")}
          </Button>
        </PermissionGuard>
      </div>

      <PpatDeedsList
        fixedFilter={{ matter_id: matterId }}
        emptyTitleKey="matterDeedsEmptyTitle"
        emptyDescriptionKey="matterDeedsEmptyDescription"
        showCreate={false}
      />
    </>
  );
}
