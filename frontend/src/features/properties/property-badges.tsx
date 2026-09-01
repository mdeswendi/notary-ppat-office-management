"use client";

import { Archive } from "lucide-react";
import { useTranslations } from "next-intl";

import { Badge } from "@/components/ui/badge";
import type { PropertyType } from "@/types/property";

/**
 * Property type, right type and retirement, as labelled badges (M7.3, D-121).
 *
 * **Status must not rely on colour alone** (`CLAUDE.md` section 49): every badge carries
 * its translated text, and the tint is a secondary cue rather than the information
 * itself.
 *
 * The PPAT teal is used lightly, as section 42 requires — a property type is
 * classification rather than alarm, so the badges stay neutral and only the archived
 * marker takes an accent.
 */

/**
 * What kind of land object this is.
 *
 * **Translated**, unlike `right_type` below, because `property_type` is a closed list
 * of four values the ERD gives flat — a stable machine code with a known meaning, which
 * is exactly what message keys are for (`CLAUDE.md` section 12).
 */
export function PropertyTypeBadge({ type }: { type: PropertyType }) {
  const t = useTranslations("properties");

  return (
    <Badge tone="neutral" aria-label={`${t("propertyType")}: ${t(`propertyTypes.${type}`)}`}>
      {t(`propertyTypes.${type}`)}
    </Badge>
  );
}

/**
 * The kind of right held over the land, shown as the office typed it.
 *
 * **Not translated, and not expanded.** `right_type` is open vocabulary: the ERD says
 * *"Right type **may** use stable machine codes, **for example**"*, so rendering
 * `HAK_MILIK` as "Freehold Title" would be an invented legal translation of exactly the
 * kind `CLAUDE.md` section 9 forbids — and would be wrong for any code the office typed
 * that the ERD never listed. The code is shown verbatim, the way `deed_type_code` is on
 * both deed surfaces.
 */
export function RightTypeBadge({ code }: { code: string | null }) {
  const t = useTranslations("properties");

  if (code === null || code === "") {
    return <span className="text-muted-foreground text-sm">—</span>;
  }

  return <Badge aria-label={`${t("rightType")}: ${code}`}>{code}</Badge>;
}

/**
 * The retirement marker.
 *
 * Renders nothing while a Property is in active use, so the badge means something when
 * it appears. The icon is decorative and the text carries the meaning — an icon alone
 * would be the shape-only signal section 49 rules out.
 *
 * **This is not a status vocabulary.** `properties.status` has no values in the ERD and
 * nothing writes it; `is_archived` comes from `deleted_at`, which is structural. So
 * there is no `ACTIVE` badge either — a parcel that is not retired simply shows no
 * marker, rather than one asserting a lifecycle state the product does not have.
 */
export function PropertyArchivedBadge({ isArchived }: { isArchived: boolean }) {
  const t = useTranslations("properties");

  if (!isArchived) {
    return null;
  }

  return (
    <Badge tone="ppat" withIcon>
      <Archive aria-hidden="true" className="size-3" />
      {t("archived")}
    </Badge>
  );
}

/**
 * The role a parcel plays in one Matter.
 *
 * **Opaque and rendered verbatim**, like `right_type`: `03_DATABASE_ERD.md` calls
 * `TRANSACTION_OBJECT`, `COLLATERAL` and `RELATED_PROPERTY` *"Example role codes"*, so
 * no translation table exists and inventing one would present three values as the
 * vocabulary when the ERD declined to.
 */
export function PropertyRoleBadge({ code }: { code: string | null }) {
  const t = useTranslations("properties");

  if (code === null || code === "") {
    return <span className="text-muted-foreground text-sm">—</span>;
  }

  return <Badge aria-label={`${t("roleCode")}: ${code}`}>{code}</Badge>;
}
