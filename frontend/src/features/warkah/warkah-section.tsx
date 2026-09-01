"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { EmptyState } from "@/components/feedback/empty-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  WarkahItemCollectedBadge,
  WarkahRequirementBadge,
  WarkahStatusBadge,
} from "@/features/warkah/warkah-badges";
import { isNotStarted, toWarkahErrorKey } from "@/features/warkah/warkah-errors";
import { WarkahItemDocuments } from "@/features/warkah/warkah-item-documents";
import { WarkahItemForm } from "@/features/warkah/warkah-item-form";
import { Link } from "@/i18n/navigation";
import {
  getWarkah,
  getWarkahItems,
  removeWarkahItem,
  setWarkahStatus,
  verifyWarkah,
  warkahKeys,
} from "@/services/warkah";
import type { WarkahItem } from "@/types/warkah";

/**
 * Warkah — the supporting documents bound with a PPAT Deed (M7.4, D-121).
 *
 * **A section, not a tab.** The M7.4 brief asked for a tab on the deed page; the
 * repository has no `Tabs` primitive, and adding one is a design decision affecting
 * every detail page rather than a side effect of a Warkah milestone — the ruling every
 * milestone since M5.2 has made.
 *
 * ## What the percentage means, said in words
 *
 * **Every line this office listed has a file against it.** Not that the bundle is
 * legally sufficient: the mandatory Warkah composition per deed type is open question
 * three, no requirement template drives the number (D-104), and the M7 lock section 8.2
 * is explicit that *"a Warkah that is arithmetically full and legally short is exactly
 * the failure this refusal prevents."*
 *
 * So the figure is rendered as a fraction — *7 of 9 lines* — beside the percentage, and
 * a note says what it counts. A reader who sees the fraction understands; one who sees
 * `78%` does not.
 *
 * ## Three controls, three capabilities, and two acts that do not exist
 *
 * ```text
 * Send for review / reopen   ppat.warkah.update   INCOMPLETE, UNDER_REVIEW
 * Verify                     ppat.warkah.verify   COMPLETE, stamping the pair
 * Add and remove lines       ppat.warkah.update
 * Attach and detach files    ppat.warkah.upload
 * ```
 *
 * **There is no Finalize control and no Archive control.** Both codes are canonical and
 * both stay unimplemented, because their trigger is open question eight — *"what are
 * the binding/archiving requirements for deeds and supporting Warkah?"* — and
 * `09_PPAT_WORKFLOW.md` section 2 names exactly those obligations as *"precisely the
 * kind of rule that must not be reconstructed from memory."* Offering a button nobody
 * can be granted would be worse than its absence (D-064, O-041).
 *
 * **There is no item status control either.** `ppat_warkah_items.status` has no
 * vocabulary in the ERD; what a line's state actually is — collected or not — follows
 * from attaching a document.
 *
 * ## A bundle nobody has started is an empty state, not a failure
 *
 * `GET .../warkah/items` answers 200 with `warkah_started: false` while no bundle
 * exists, so the section renders its checklist empty and offers the control that starts
 * one. The bundle materialises on the first line, because there is no
 * `ppat.warkah.create` capability for a separate "start" act to answer to.
 *
 * A **403** is a different thing and reads as one: Warkah is its own capability family,
 * so a reader who can open the deed and not its supporting bundle sees an honest
 * failure rather than a fabricated empty section.
 */
export function WarkahSection({ deedId }: { deedId: string }) {
  const t = useTranslations("warkah");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [actionError, setActionError] = useState<string | null>(null);

  const query = useQuery({
    queryKey: warkahKeys.items(deedId),
    queryFn: () => getWarkahItems(deedId),
  });

  // Present only once the office has started a bundle. A 404 here is the ordinary
  // "nothing started" answer, so the section does not treat it as an error.
  const bundle = useQuery({
    queryKey: warkahKeys.forDeed(deedId),
    queryFn: () => getWarkah(deedId),
    retry: false,
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: warkahKeys.items(deedId) });
    await queryClient.invalidateQueries({ queryKey: warkahKeys.forDeed(deedId) });
    await queryClient.invalidateQueries({ queryKey: warkahKeys.all() });
  };

  const onError = (error: unknown) => setActionError(t(`errors.${toWarkahErrorKey(error)}`));

  const review = useMutation({
    mutationFn: (next: "INCOMPLETE" | "UNDER_REVIEW") => setWarkahStatus(deedId, next),
    onSuccess: invalidate,
    onError,
  });

  const verify = useMutation({
    mutationFn: () => verifyWarkah(deedId),
    onSuccess: invalidate,
    onError,
  });

  const remove = useMutation({
    mutationFn: (itemId: string) => removeWarkahItem(deedId, itemId),
    onSuccess: invalidate,
    onError,
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        {[0, 1].map((row) => (
          <Skeleton key={row} className="h-12 w-full" />
        ))}
      </div>
    );
  }

  if (query.isError) {
    return (
      <div className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t("title")}</h2>
        <BaseErrorState
          title={t("errorTitle")}
          description={t(`errors.${toWarkahErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      </div>
    );
  }

  const items = query.data?.data ?? [];
  const meta = query.data?.meta;
  const started = meta?.warkah_started ?? false;

  const warkah = bundle.isSuccess ? bundle.data : null;
  const notStarted = bundle.isError && isNotStarted(bundle.error);

  const canManage = meta?.can_manage ?? false;
  const canVerify = warkah?.can_verify ?? false;

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <div className="flex flex-wrap items-center gap-3">
            {/* Indonesian legal terminology, used exactly as written. */}
            <h2 className="text-lg font-semibold">{t("title")}</h2>
            {warkah ? <WarkahStatusBadge status={warkah.status} /> : null}
          </div>
          <p className="text-muted-foreground text-sm">{t("hint")}</p>
        </div>

        {started ? (
          <div className="text-right">
            <p className="text-muted-foreground text-xs">{t("completeness")}</p>
            <p className="text-sm font-medium tabular-nums">
              {meta?.completeness_percentage ?? 0}%
            </p>
            <p className="text-muted-foreground text-xs tabular-nums">
              {t("collectedOf", { collected: meta?.collected ?? 0, total: meta?.total ?? 0 })}
            </p>
          </div>
        ) : null}
      </div>

      {actionError ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {actionError}
        </p>
      ) : null}

      {warkah?.verified_at ? (
        <p className="text-muted-foreground border-border rounded-md border px-3 py-2 text-sm">
          {t("verifiedNotice", {
            date: warkah.verified_at.slice(0, 10),
            name: warkah.verified_by?.name ?? "—",
          })}
        </p>
      ) : null}

      {/*
        Two controls, two capabilities. There is no Finalize and no Archive — see the
        component docblock.
      */}
      <div className="flex flex-wrap gap-2">
        {canManage && warkah?.status !== "UNDER_REVIEW" ? (
          <Button
            variant="outline"
            size="sm"
            disabled={review.isPending}
            onClick={() => {
              setActionError(null);
              review.mutate("UNDER_REVIEW");
            }}
          >
            {t("actions.sendForReview")}
          </Button>
        ) : null}

        {canManage && warkah?.status === "UNDER_REVIEW" ? (
          <Button
            variant="outline"
            size="sm"
            disabled={review.isPending}
            onClick={() => {
              setActionError(null);
              review.mutate("INCOMPLETE");
            }}
          >
            {t("actions.reopen")}
          </Button>
        ) : null}

        {canVerify || (notStarted && !started) ? (
          <Button
            variant="outline"
            size="sm"
            disabled={verify.isPending}
            onClick={() => {
              setActionError(null);
              verify.mutate();
            }}
          >
            {t("actions.verify")}
          </Button>
        ) : null}
      </div>

      {canManage ? <WarkahItemForm deedId={deedId} /> : null}

      {items.length === 0 ? (
        <EmptyState
          title={started ? t("emptyTitle") : t("notStartedTitle")}
          description={started ? t("emptyDescription") : t("notStartedDescription")}
        />
      ) : (
        <ol className="flex flex-col gap-3">
          {items.map((item) => (
            <WarkahLine
              key={item.id}
              deedId={deedId}
              item={item}
              onRemove={() => {
                setActionError(null);

                if (window.confirm(t("removeItemConfirm"))) {
                  remove.mutate(item.id);
                }
              }}
              removing={remove.isPending}
            />
          ))}
        </ol>
      )}

      {/*
        The one sentence this whole surface exists to keep honest. The M7 lock section
        8.2: *"100% does not mean complete in law. It means every item this office
        listed has a document. The interface must say so."*
      */}
      <p className="text-muted-foreground text-xs">{t("completenessHint")}</p>
    </div>
  );
}

/**
 * One line of the checklist.
 *
 * Titles are shown in **both languages**, because `title_id` and `title_en` are
 * bilingual database fields rather than UI strings — the office wrote both, and a
 * reader in either language should see what was written.
 *
 * The line's state is `has_document`, not a status: `ppat_warkah_items.status` has no
 * vocabulary in the ERD.
 */
function WarkahLine({
  deedId,
  item,
  onRemove,
  removing,
}: {
  deedId: string;
  item: WarkahItem;
  onRemove: () => void;
  removing: boolean;
}) {
  const t = useTranslations("warkah");

  return (
    <li className="border-border flex flex-col gap-3 rounded-lg border p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-medium">{item.title_id}</span>
            <WarkahItemCollectedBadge hasDocument={item.has_document} />
            <WarkahRequirementBadge code={item.requirement_code} />
          </div>

          <span className="text-muted-foreground text-xs">{item.title_en}</span>

          {item.party ? (
            <span className="text-muted-foreground text-xs">
              {t("party")}:{" "}
              {/*
                A Party the reader cannot open renders as plain text rather than a link
                that answers 403 — the M4.5 rule. The line itself always renders: it is
                the office's own checklist.
              */}
              {item.party.can_view_party ? (
                <Link
                  href={`/parties/${item.party.id}`}
                  className="underline-offset-4 hover:underline"
                >
                  {item.party.display_name}
                </Link>
              ) : (
                <span>{item.party.display_name}</span>
              )}
            </span>
          ) : null}

          {item.notes ? <span className="text-muted-foreground text-xs">{item.notes}</span> : null}
        </div>

        {item.can_manage ? (
          <Button
            variant="ghost"
            size="sm"
            aria-label={t("removeItem")}
            disabled={removing}
            onClick={onRemove}
          >
            <Trash2 aria-hidden="true" className="size-4" />
          </Button>
        ) : null}
      </div>

      <WarkahItemDocuments deedId={deedId} item={item} />
    </li>
  );
}
