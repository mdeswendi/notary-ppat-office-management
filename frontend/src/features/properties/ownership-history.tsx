"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { toPropertyErrorKey } from "@/features/properties/property-errors";
import { AddOwnerForm } from "@/features/properties/add-owner-form";
import { Link } from "@/i18n/navigation";
import { getPropertyOwners, propertyKeys, updatePropertyOwner } from "@/services/properties";
import type { PropertyOwner } from "@/types/property";

/**
 * A Property's chain of title (M7.3, D-121).
 *
 * ## Every link, and closed ones are not deleted ones
 *
 * The list shows the whole chain — current holders and past ones — because that is what
 * makes this history rather than a current state somebody keeps editing
 * (`CLAUDE.md` section 63). A closed link keeps the party and the share the office
 * recorded, for good.
 *
 * ## There is no remove control, and that is not an omission
 *
 * `property_owners` has **no `deleted_at`** in the ERD, so a delete could only be a hard
 * one, and hard-deleting a link destroys exactly the history the table exists to keep
 * (sections 30 and 63). The M7.3 brief asked for a *"soft delete ownership"* endpoint;
 * there is no column for it and no route.
 *
 * **Ending an ownership is closing the link** — stamping an end date, which is what a
 * chain of title does when land changes hands. The control below says "close", not
 * "remove", because that is what it does.
 *
 * ## Several owners may be current at once
 *
 * Co-ownership is ordinary for Indonesian land, and the M7 lock section 7.2 is explicit
 * that `is_current` is *"a 'this row applies now' flag on many rows, not a 'this is the
 * one' pointer"*. So the current holders are a group, the total of their shares is
 * displayed, and **nothing judges that total** — whether shares must sum to 100 is a
 * rule about Indonesian co-ownership that no canonical document states.
 *
 * ## Its own capability, so its own honest failure
 *
 * This section asks `GET /properties/{id}/owners`, authorized by
 * `properties.ownership.view`. A reader who may open the parcel and not its title sees
 * this section fail rather than see a fabricated empty one — reading a Property does not
 * read who owns it, and the catalogue drew that line before anything implemented it.
 */
export function OwnershipHistory({
  propertyId,
  isArchived,
}: {
  propertyId: string;
  /** An archived parcel's chain is read-only: retirement makes the record read-only. */
  isArchived: boolean;
}) {
  const t = useTranslations("properties");
  const tActions = useTranslations("actions");

  const query = useQuery({
    queryKey: propertyKeys.owners(propertyId),
    queryFn: () => getPropertyOwners(propertyId),
  });

  const links = query.data?.data ?? [];
  const canUpdate = (query.data?.meta.can_update ?? false) && !isArchived;
  const total = query.data?.meta.current_ownership_total ?? 0;

  const current = links.filter((link) => link.is_current);
  const past = links.filter((link) => !link.is_current);

  // **Shown only when somebody recorded a share.** A total of 0% under a holder whose
  // percentage the office never wrote would read as "this person owns nothing", which
  // is a different statement from "no figure was recorded".
  const showsTotal = current.some((link) => link.ownership_percentage !== null);

  return (
    <div className="flex flex-col gap-5">
      {/*
        The heading renders in every state, loading and failure included, so a reader
        can tell *which* section could not load. Returning an error component in place
        of the whole section would drop the one label that says what failed.
      */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h2 className="text-lg font-semibold">{t("ownership.title")}</h2>
          <p className="text-muted-foreground text-sm">{t("ownership.hint")}</p>
        </div>

        {showsTotal ? (
          <div className="text-right">
            <p className="text-muted-foreground text-xs">{t("ownership.currentTotal")}</p>
            <p className="text-sm font-medium tabular-nums">{formatShare(total)}</p>
          </div>
        ) : null}
      </div>

      {query.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          {[0, 1].map((row) => (
            <Skeleton key={row} className="h-12 w-full" />
          ))}
        </div>
      ) : query.isError ? (
        <BaseErrorState
          title={t("ownership.errorTitle")}
          description={t(`errors.${toPropertyErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : (
        <>
          {canUpdate ? (
            <AddOwnerForm propertyId={propertyId} hasCurrentOwners={current.length > 0} />
          ) : null}

          {links.length === 0 ? (
            <BaseErrorState
              title={t("ownership.emptyTitle")}
              description={t("ownership.emptyDescription")}
            />
          ) : (
            <ol className="flex flex-col gap-3">
              {[...current, ...past].map((link) => (
                <OwnershipLink
                  key={link.id}
                  propertyId={propertyId}
                  link={link}
                  canUpdate={canUpdate && link.can_update}
                />
              ))}
            </ol>
          )}

          {/*
            Whether co-owners' shares must total 100 is an open question the M7 lock
            records (section 7.2), so the figure above is arithmetic and carries no
            judgement. Saying so is the honest alternative to a validation rule nobody
            may write.
          */}
          <p className="text-muted-foreground text-xs">{t("ownership.totalHint")}</p>
        </>
      )}
    </div>
  );
}

/**
 * One link, and the control that closes it.
 *
 * The current holders are marked, not colour-coded alone (`CLAUDE.md` section 49): the
 * badge carries its word, and the closed ones carry their end date.
 */
function OwnershipLink({
  propertyId,
  link,
  canUpdate,
}: {
  propertyId: string;
  link: PropertyOwner;
  canUpdate: boolean;
}) {
  const t = useTranslations("properties");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [closing, setClosing] = useState(false);
  const [until, setUntil] = useState("");
  const [error, setError] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: (effectiveUntil: string) =>
      updatePropertyOwner(propertyId, link.id, { effective_until: effectiveUntil }),
    onSuccess: async () => {
      setError(null);
      setClosing(false);
      await queryClient.invalidateQueries({ queryKey: propertyKeys.all() });
    },
    onError: (failure: unknown) => setError(t(`errors.${toPropertyErrorKey(failure)}`)),
  });

  const name = link.party?.display_name ?? t("unnamedParty");

  return (
    <li className="border-border flex flex-col gap-2 rounded-lg border p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <div className="flex flex-wrap items-center gap-2">
            {/*
              A Party the reader cannot open renders as plain text rather than a link
              that answers 403 — the M4.5 rule. The row itself always renders: the link
              is a fact about the land, and hiding it would misreport the title.
            */}
            {link.party && link.party.can_view_party ? (
              <Link
                href={`/parties/${link.party.id}`}
                className="font-medium underline-offset-4 hover:underline"
              >
                {name}
              </Link>
            ) : (
              <span className="font-medium">{name}</span>
            )}

            {link.is_current ? (
              <span className="border-ppat/40 text-ppat rounded-full border px-2 py-0.5 text-xs">
                {t("ownership.current")}
              </span>
            ) : (
              <span className="border-border text-muted-foreground rounded-full border px-2 py-0.5 text-xs">
                {t("ownership.closed")}
              </span>
            )}
          </div>

          <p className="text-muted-foreground text-xs">
            {link.effective_from ?? "—"} → {link.effective_until ?? t("ownership.openEnded")}
          </p>

          {link.source_matter ? (
            <p className="text-muted-foreground text-xs">
              {t("ownership.sourceMatter")}:{" "}
              <Link
                href={`/ppat/matters/${link.source_matter.id}`}
                className="underline-offset-4 hover:underline"
              >
                {link.source_matter.matter_number}
              </Link>
            </p>
          ) : null}
        </div>

        <div className="flex items-center gap-3">
          <span className="text-sm tabular-nums">
            {link.ownership_percentage === null
              ? t("ownership.shareUnrecorded")
              : formatShare(link.ownership_percentage)}
          </span>

          {canUpdate && link.is_current ? (
            <Button variant="outline" size="sm" onClick={() => setClosing((open) => !open)}>
              {t("ownership.close")}
            </Button>
          ) : null}
        </div>
      </div>

      {error ? (
        <p role="alert" className="text-destructive text-sm">
          {error}
        </p>
      ) : null}

      {closing ? (
        <form
          className="border-border flex flex-wrap items-end gap-3 border-t pt-3"
          onSubmit={(event) => {
            event.preventDefault();
            setError(null);

            if (until !== "") {
              mutation.mutate(until);
            }
          }}
        >
          <div className="flex flex-col gap-2">
            <Label htmlFor={`close-${link.id}`}>{t("ownership.effectiveUntil")}</Label>
            <Input
              id={`close-${link.id}`}
              type="date"
              value={until}
              onChange={(event) => setUntil(event.target.value)}
            />
          </div>

          <Button type="submit" size="sm" disabled={until === "" || mutation.isPending}>
            {mutation.isPending ? tActions("saving") : t("ownership.close")}
          </Button>

          {/*
            Closing is not removing. The link stays in the chain with its party and
            share intact — there is no delete, because there is no column for one and
            history is never destroyed.
          */}
          <p className="text-muted-foreground w-full text-xs">{t("ownership.closeHint")}</p>
        </form>
      ) : null}
    </li>
  );
}

/**
 * A share, or a dash.
 *
 * Rendered with at most two decimals, matching the column's `decimal(5,2)`. No
 * comparison against 100 happens anywhere.
 */
function formatShare(value: number): string {
  return `${Number.isInteger(value) ? value : value.toFixed(2)}%`;
}
