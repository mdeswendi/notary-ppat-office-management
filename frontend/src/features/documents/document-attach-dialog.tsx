"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Search } from "lucide-react";
import { useTranslations } from "next-intl";

import { Badge } from "@/components/ui/badge";
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
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { toDocumentErrorKey } from "@/features/documents/document-errors";
import {
  attachDocument,
  documentRelationKeys,
  getRelationCandidates,
} from "@/services/document-relations";
import { documentQueryKeys } from "@/services/documents";
import {
  DOCUMENT_RELATION_TYPES,
  type DocumentRelationCandidate,
  type DocumentRelationType,
} from "@/types/document-relation";

/**
 * Attach a Document to a Party, Project or Matter (M5.3, D-118).
 *
 * **The picker only offers what the caller can already reach.** Candidates come
 * from each domain's own list endpoint, so every row is bounded by that domain's
 * capability and Data Scope — the dialog can never show something the attach
 * endpoint would then refuse for lack of reach. Matters are fetched from both
 * domain roots and merged, because the two capabilities are independent (D-101).
 *
 * **Three types, not seven.** `property`, `notary_deed` and `ppat_deed` are
 * recommended by the ERD and their tables do not exist (D-115). They are absent
 * from the selector rather than shown disabled: a control that cannot work is dead
 * UI that invites "why is this greyed out?", and the honest answer is that the
 * milestone building those records will add them here.
 *
 * A 422 for a duplicate is a real outcome rather than a fault — somebody clicked
 * twice, or the document was already filed there — so it reads as a calm message.
 */
export function DocumentAttachDialog({
  documentId,
  open,
  onOpenChange,
}: {
  documentId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={open} onOpenChange={(next) => (next ? undefined : onOpenChange(false))}>
      <DialogContent>
        {/* The body mounts and unmounts with the dialog, which is what resets the
            type, the search and the selection. See `EntityDocumentPicker` for the
            reasoning — an effect keyed on `open` would be state written during
            commit. */}
        {open ? <AttachBody documentId={documentId} onOpenChange={onOpenChange} /> : null}
      </DialogContent>
    </Dialog>
  );
}

function AttachBody({
  documentId,
  onOpenChange,
}: {
  documentId: string;
  onOpenChange: (open: boolean) => void;
}) {
  const t = useTranslations("documents");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [type, setType] = useState<DocumentRelationType>("project");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [selected, setSelected] = useState<string | null>(null);
  const [errorKey, setErrorKey] = useState<string | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => setSearch(searchInput.trim()), 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const candidates = useQuery({
    queryKey: documentRelationKeys.candidates(type, search),
    queryFn: () => getRelationCandidates(type, search),
  });

  const mutation = useMutation({
    mutationFn: (entityId: string) =>
      attachDocument(documentId, { entity_type: type, entity_id: entityId }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: documentQueryKeys.all() });
      onOpenChange(false);
    },
    onError: (error: unknown) => setErrorKey(toDocumentErrorKey(error)),
  });

  const rows = candidates.data ?? [];

  return (
    <>
      <DialogHeader>
        <DialogTitle>{t("relations.attachTitle")}</DialogTitle>
        <DialogDescription>{t("relations.attachDescription")}</DialogDescription>
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
        <Label htmlFor="relation-type">{t("relations.entityType")}</Label>
        <Select
          id="relation-type"
          value={type}
          onChange={(event) => {
            setType(event.target.value as DocumentRelationType);
            setSelected(null);
            setErrorKey(null);
          }}
        >
          {DOCUMENT_RELATION_TYPES.map((option) => (
            <option key={option} value={option}>
              {t(`relations.types.${option}`)}
            </option>
          ))}
        </Select>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="relation-search">{t("relations.selectEntity")}</Label>
        <div className="relative">
          <Search
            aria-hidden="true"
            className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
          />
          <Input
            id="relation-search"
            className="pl-9"
            placeholder={t("relations.searchPlaceholder")}
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
          />
        </div>
      </div>

      <div className="border-border max-h-64 overflow-y-auto rounded-md border">
        {candidates.isPending ? (
          <div className="flex flex-col gap-2 p-3" aria-busy="true" aria-live="polite">
            <span className="sr-only">{t("loading")}</span>
            <Skeleton className="h-8 w-full" />
            <Skeleton className="h-8 w-full" />
          </div>
        ) : rows.length === 0 ? (
          <p className="text-muted-foreground p-4 text-sm">{t("relations.noCandidates")}</p>
        ) : (
          <ul className="divide-border divide-y">
            {rows.map((candidate) => (
              <li key={`${candidate.entity_type}-${candidate.id}`}>
                <CandidateRow
                  candidate={candidate}
                  isSelected={selected === candidate.id}
                  onSelect={() => {
                    setSelected(candidate.id);
                    setErrorKey(null);
                  }}
                />
              </li>
            ))}
          </ul>
        )}
      </div>

      <DialogFooter>
        <Button variant="outline" onClick={() => onOpenChange(false)}>
          {tActions("cancel")}
        </Button>
        <Button
          disabled={selected === null || mutation.isPending}
          onClick={() => {
            if (selected !== null) {
              mutation.mutate(selected);
            }
          }}
        >
          {mutation.isPending ? tActions("saving") : t("relations.attach")}
        </Button>
      </DialogFooter>
    </>
  );
}

/**
 * One candidate, as a real button so it is reachable by keyboard and announces its
 * selected state rather than only looking selected.
 */
function CandidateRow({
  candidate,
  isSelected,
  onSelect,
}: {
  candidate: DocumentRelationCandidate;
  isSelected: boolean;
  onSelect: () => void;
}) {
  return (
    <button
      type="button"
      aria-pressed={isSelected}
      onClick={onSelect}
      className={`focus-visible:ring-ring flex w-full items-center gap-3 px-3 py-2 text-left text-sm focus-visible:ring-2 focus-visible:outline-none ${
        isSelected ? "bg-primary/10 text-foreground" : "hover:bg-muted/50"
      }`}
    >
      {candidate.reference ? (
        <span className="text-muted-foreground shrink-0 font-mono text-xs">
          {candidate.reference}
        </span>
      ) : null}
      <span className="truncate">{candidate.label}</span>
      {candidate.domain ? <Badge className="ml-auto shrink-0">{candidate.domain}</Badge> : null}
    </button>
  );
}
