"use client";

import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toCompanyErrorKey } from "@/features/companies/company-errors";
import type { RelationshipCandidate, RelationshipIndividual } from "@/types/company-relationship";

/**
 * Pieces both relationship sections use.
 *
 * Shared rather than duplicated because the two surfaces must behave
 * identically in the ways that matter — a relationship is ended the same way
 * whichever category it belongs to, and history is presented the same way. What
 * is *not* shared is anything category-specific: the type choices, the
 * percentage, the position. Those stay in their own sections so neither can
 * accidentally offer the other's fields.
 */

/**
 * The person a relationship points at.
 *
 * Two facts and no more: a name and whether the record has since been archived.
 * A relationship view permission is not a sensitive identity permission (D-082),
 * and there is nothing else in the payload to show.
 */
export function RelationshipPerson({ individual }: { individual: RelationshipIndividual | null }) {
  const t = useTranslations("companyRelationships");

  if (individual === null) {
    return <span className="text-muted-foreground text-sm">{t("unknownPerson")}</span>;
  }

  return (
    <span className="flex flex-wrap items-center gap-2">
      <span className="font-medium">{individual.display_name ?? t("unknownPerson")}</span>
      {individual.is_archived ? (
        <span className="border-border text-muted-foreground rounded-full border px-2 py-0.5 text-xs">
          {t("archivedIndividual")}
        </span>
      ) : null}
    </span>
  );
}

/**
 * The period a relationship covers.
 *
 * Dates are shown exactly as recorded, and "current" means the one thing the
 * schema says it means — no end date. Nothing here compares a date to today or
 * infers anything from a future start.
 */
export function RelationshipPeriod({
  effectiveFrom,
  effectiveUntil,
  isCurrent,
}: {
  effectiveFrom: string | null;
  effectiveUntil: string | null;
  isCurrent: boolean;
}) {
  const t = useTranslations("companyRelationships");

  return (
    <span className="text-muted-foreground text-sm">
      {t("effectiveFromLabel")}: {effectiveFrom ?? "—"}
      {" · "}
      {t("effectiveUntilLabel")}: {effectiveUntil ?? (isCurrent ? t("current") : "—")}
    </span>
  );
}

/**
 * A same-Office candidate chooser.
 *
 * The list comes from the relationship options endpoint, which returns only
 * active Individuals in the parent Company's Office and only their id and name.
 * There is nothing to search by but the name, and nothing else to display.
 */
export function CandidateSelect({
  id,
  candidates,
  value,
  onChange,
  loading,
}: {
  id: string;
  candidates: RelationshipCandidate[];
  value: string;
  onChange: (value: string) => void;
  loading: boolean;
}) {
  const t = useTranslations("companyRelationships");

  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{t("personLabel")}</Label>
      <select
        id={id}
        className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
        value={value}
        onChange={(event) => onChange(event.target.value)}
      >
        <option value="">{loading ? t("personLoading") : t("personPlaceholder")}</option>
        {candidates.map((candidate) => (
          <option key={candidate.id} value={candidate.id}>
            {candidate.display_name ?? candidate.id}
          </option>
        ))}
      </select>
      {!loading && candidates.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("noCandidates")}</p>
      ) : null}
    </div>
  );
}

/**
 * Ending a relationship, behind a confirmation.
 *
 * **Never called Delete, because it is not one.** The row stays, keeps its
 * person and its type, and remains in the history list — the dialog says so.
 * Claiming a deletion would be false, and claiming a legal termination would be
 * inventing something the software has no authority to assert (D-083).
 *
 * The end date is collected explicitly. Defaulting it would mean the software
 * deciding when an appointment ceased.
 */
export function EndRelationshipDialog({
  open,
  onOpenChange,
  onEnd,
  onEnded,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onEnd: (effectiveUntil: string) => Promise<unknown>;
  onEnded: () => void;
}) {
  const t = useTranslations("companyRelationships");
  const tActions = useTranslations("actions");

  const [effectiveUntil, setEffectiveUntil] = useState("");
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => onEnd(effectiveUntil),
    onSuccess: () => {
      setEffectiveUntil("");
      setErrorKey(null);
      onEnded();
    },
    onError: (error: unknown) => setErrorKey(toCompanyErrorKey(error)),
  });

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) {
          setErrorKey(null);
        }
        onOpenChange(next);
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("endTitle")}</DialogTitle>
          <DialogDescription>{t("endDescription")}</DialogDescription>
        </DialogHeader>

        {errorKey ? (
          <p
            role="alert"
            className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
          >
            {t(`errors.${errorKey}`)}
          </p>
        ) : null}

        <div className="flex flex-col gap-2">
          <Label htmlFor="end-effective-until">{t("effectiveUntilLabel")}</Label>
          <Input
            id="end-effective-until"
            type="date"
            value={effectiveUntil}
            onChange={(event) => setEffectiveUntil(event.target.value)}
          />
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {tActions("cancel")}
          </Button>
          <Button
            disabled={mutation.isPending || effectiveUntil === ""}
            onClick={() => mutation.mutate()}
          >
            {mutation.isPending ? tActions("saving") : t("endConfirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
