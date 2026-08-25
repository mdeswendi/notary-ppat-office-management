"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  PpatDeedReadOnlyBadge,
  PpatDeedStatusBadge,
  PpatDeedTypeBadge,
} from "@/features/ppat/deed-badges";
import { hasFieldError, toPpatErrorKey } from "@/features/ppat/deed-errors";
import { Link } from "@/i18n/navigation";
import {
  approvePpatDeed,
  finalizePpatDeed,
  getPpatDeed,
  ppatDeedKeys,
  recordPpatDeedNumber,
  reviewPpatDeed,
} from "@/services/ppat";
import type { PpatDeed } from "@/types/ppat";

/**
 * One PPAT Deed, and the acts its capabilities allow (M7.2, D-121).
 *
 * **Sections, not tabs.** The repository has no `Tabs` primitive, and adding one is a
 * design decision affecting every detail page rather than a side effect of a deed
 * milestone — the ruling M5.2, M5.3, M5.4 and M6.2 each followed on the same question.
 *
 * **Every control is gated on a backend-computed flag**, not on a permission string
 * the browser assembled. The flags fold in **status eligibility as well as
 * capability**, so a control that would answer 422 is simply absent: `can_approve` is
 * false on anything nobody has reviewed, `can_finalize` false on anything not yet
 * approved, `can_update` false on a finalized deed.
 *
 * **Five separate capabilities, five separate controls.** Editing, reviewing,
 * approving, finalizing and numbering each answer to their own code. Approval in
 * particular is gated on `ppat.deeds.approve` and on nothing else: the brief said
 * *"hanya PRINCIPAL/SUPER_ADMIN"*, and restricting it to the Principal is done by
 * granting that capability to that role alone. No role name appears here (D-032).
 *
 * **There is no delete, void or supersede control**, because no canonical capability
 * authorizes any of them and the correction mechanisms that would need them are open
 * question nine. Offering a button nobody can be granted would be worse than its
 * absence.
 *
 * **There is no Warkah section yet.** The PPAT deed's supporting legal documents are
 * its Warkah, which is a separate aggregate answering to its own family of six
 * `ppat.warkah.*` capabilities — M7.4's surface. The deed page will grow a section
 * that asks that endpoint separately, exactly as the Notary page does for its Minuta,
 * so a reader holding one capability and not the other sees an honest failure rather
 * than a fabricated blank.
 */
export function PpatDeedDetail({ deedId }: { deedId: string }) {
  const t = useTranslations("ppat");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [actionError, setActionError] = useState<string | null>(null);

  const query = useQuery({
    queryKey: ppatDeedKeys.detail(deedId),
    queryFn: () => getPpatDeed(deedId),
  });

  const deed = query.data;

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ppatDeedKeys.all() });
  };

  const onError = (error: unknown) => setActionError(t(`errors.${toPpatErrorKey(error)}`));

  const review = useMutation({
    mutationFn: () => reviewPpatDeed(deedId),
    onSuccess: invalidate,
    onError,
  });

  const approve = useMutation({
    mutationFn: () => approvePpatDeed(deedId),
    onSuccess: invalidate,
    onError,
  });

  const finalize = useMutation({
    mutationFn: () => finalizePpatDeed(deedId),
    onSuccess: invalidate,
    onError,
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  if (query.isError || !deed) {
    return (
      <BaseErrorState
        title={t("deedErrorTitle")}
        description={t(`errors.${toPpatErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">{deed.title}</h1>
          <PpatDeedStatusBadge status={deed.status} />
          <PpatDeedTypeBadge code={deed.deed_type_code} />
          <PpatDeedReadOnlyBadge isReadOnly={deed.is_read_only} />
        </div>

        {actionError ? (
          <p
            role="alert"
            className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
          >
            {actionError}
          </p>
        ) : null}

        {deed.is_read_only ? (
          <p className="text-muted-foreground border-border rounded-md border px-3 py-2 text-sm">
            {t("finalizedNotice")}
          </p>
        ) : null}

        <div className="flex flex-wrap gap-2">
          {deed.can_review ? (
            <Button
              variant="outline"
              disabled={review.isPending}
              onClick={() => {
                setActionError(null);
                review.mutate();
              }}
            >
              {t("submitForReview")}
            </Button>
          ) : null}

          {deed.can_approve ? (
            <Button
              variant="outline"
              disabled={approve.isPending}
              onClick={() => {
                setActionError(null);
                approve.mutate();
              }}
            >
              {t("approve")}
            </Button>
          ) : null}

          {deed.can_finalize ? (
            <Button
              variant="outline"
              disabled={finalize.isPending}
              onClick={() => {
                setActionError(null);

                // Finalizing makes the record read-only and nothing in the product
                // reverses it — there is no correction mechanism (D-121) — so it is
                // confirmed rather than one click.
                if (window.confirm(t("finalizeConfirm"))) {
                  finalize.mutate();
                }
              }}
            >
              {t("finalize")}
            </Button>
          ) : null}
        </div>
      </header>

      <section className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t("deedOverview")}</h2>

        <dl className="border-border grid gap-x-8 gap-y-3 rounded-lg border p-4 sm:grid-cols-2">
          <Detail label={t("deedNumber")} value={deed.deed_number ?? t("unnumbered")} />
          <Detail label={t("deedDate")} value={deed.deed_date ?? "—"} />

          {deed.matter ? (
            <div className="flex flex-col gap-1">
              <dt className="text-sm font-medium">{t("matterLabel")}</dt>
              <dd className="text-sm">
                <Link
                  href={`/ppat/matters/${deed.matter.id}`}
                  className="underline-offset-4 hover:underline"
                >
                  {deed.matter.matter_number} — {deed.matter.title}
                </Link>
              </dd>
            </div>
          ) : null}

          {deed.office ? <Detail label={t("officeLabel")} value={deed.office.name} /> : null}
        </dl>
      </section>

      <section className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t("deedTimeline")}</h2>

        <dl className="border-border grid gap-x-8 gap-y-3 rounded-lg border p-4 sm:grid-cols-3">
          <Detail label={t("reviewedAt")} value={stamp(deed.reviewed_at, deed.reviewed_by?.name)} />
          <Detail label={t("approvedAt")} value={stamp(deed.approved_at, deed.approved_by?.name)} />
          <Detail
            label={t("finalizedAt")}
            value={stamp(deed.finalized_at, deed.finalized_by?.name)}
          />
        </dl>

        {/*
          The three pairs above are what the record itself preserves. A full event
          history belongs to the audit store, which does not exist — D-115 rules it
          required, absent, and not to be improvised, and M5.3, M5.4, M6.1 and M7.1
          each declined to invent one. The M7.2 brief asked for an activity log on
          status changes; a placeholder here would be exactly the improvisation D-115
          forbids, so the record's own stamps are what this page shows.
        */}
        <p className="text-muted-foreground text-xs">{t("timelineHint")}</p>
      </section>

      <DeedDocument deed={deed} />

      {deed.can_record_number ? <DeedNumberSection deed={deed} /> : null}
    </div>
  );
}

/**
 * The document the deed itself points at.
 *
 * **One slot, not three.** A Notarial Deed carries a draft and a Minuta beside its
 * final file; `ppat_deeds` carries `final_document_id` alone. The PPAT deed's
 * supporting material is the Warkah (M7.4), which is a separate aggregate rather than
 * a column here.
 *
 * **Not the Matter's whole document list.** That answers to `documents.view` with its
 * own Data Scope and already has a surface (M5.2); this one is a field of the deed
 * record, which is a different question.
 */
function DeedDocument({ deed }: { deed: PpatDeed }) {
  const t = useTranslations("ppat");

  return (
    <section className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t("deedDocuments")}</h2>

      <dl className="border-border grid gap-x-8 gap-y-3 rounded-lg border p-4 sm:grid-cols-2">
        <div className="flex flex-col gap-1">
          <dt className="text-sm font-medium">{t("documentSlots.final")}</dt>
          <dd className="text-sm">
            {deed.final_document ? (
              <Link
                href={`/documents/${deed.final_document.id}`}
                className="underline-offset-4 hover:underline"
              >
                {deed.final_document.title}
              </Link>
            ) : (
              <span className="text-muted-foreground">{t("noDocument")}</span>
            )}
          </dd>
        </div>
      </dl>
    </section>
  );
}

/**
 * Record the legal number the office assigned.
 *
 * **Its own section because it is its own capability.** `ppat.deeds.number` is
 * canonical, and folding it into finalization would assert *when* a deed is numbered —
 * half of `09_PPAT_WORKFLOW.md` section 6's fifth open question.
 *
 * **No format is suggested and none is validated.** The placeholder is deliberately
 * not an example number: showing one would teach a numbering convention this milestone
 * has no authority to invent (`CLAUDE.md` section 62).
 *
 * Offered in every status, because the office decides when — including on a deed
 * already finalized, which is why this section sits outside the read-only notice.
 */
function DeedNumberSection({ deed }: { deed: PpatDeed }) {
  const t = useTranslations("ppat");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [value, setValue] = useState(deed.deed_number ?? "");
  const [error, setError] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: (number: string) => recordPpatDeedNumber(deed.id, number),
    onSuccess: async () => {
      setError(null);
      await queryClient.invalidateQueries({ queryKey: ppatDeedKeys.all() });
    },
    onError: (failure: unknown) => {
      setError(
        hasFieldError(failure, "deed_number")
          ? t("validation.deedNumberTaken")
          : t(`errors.${toPpatErrorKey(failure)}`),
      );
    },
  });

  return (
    <section className="border-border flex flex-col gap-3 rounded-lg border p-4">
      <h3 className="text-sm font-medium">{t("recordNumberTitle")}</h3>
      <p className="text-muted-foreground text-xs">{t("recordNumberHint")}</p>

      {error ? (
        <p role="alert" className="text-destructive text-sm">
          {error}
        </p>
      ) : null}

      <form
        className="flex flex-wrap items-end gap-3"
        onSubmit={(event) => {
          event.preventDefault();
          setError(null);

          if (value.trim() !== "") {
            mutation.mutate(value.trim());
          }
        }}
      >
        <div className="flex flex-col gap-2 sm:min-w-72">
          <Label htmlFor="ppat-deed-number">{t("deedNumber")}</Label>
          <Input
            id="ppat-deed-number"
            value={value}
            onChange={(event) => setValue(event.target.value)}
            aria-invalid={error !== null}
          />
        </div>
        <Button type="submit" size="sm" disabled={value.trim() === "" || mutation.isPending}>
          {mutation.isPending ? tActions("saving") : tActions("save")}
        </Button>
      </form>
    </section>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-sm font-medium">{label}</dt>
      <dd className="text-muted-foreground text-sm">{value}</dd>
    </div>
  );
}

/**
 * An act's date and actor, or a dash.
 *
 * Sliced rather than parsed: `new Date(...).toLocaleDateString()` renders in the
 * browser's timezone, which shifts a date by a day either side of midnight and would
 * then differ between two people looking at the same deed.
 */
function stamp(at: string | null, who: string | undefined): string {
  if (at === null) {
    return "—";
  }

  return who === undefined ? at.slice(0, 10) : `${at.slice(0, 10)} · ${who}`;
}
