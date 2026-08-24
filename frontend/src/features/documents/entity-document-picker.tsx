"use client";

import { useEffect, useState } from "react";
import { keepPreviousData, useMutation, useQuery } from "@tanstack/react-query";
import { Search } from "lucide-react";
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
import { Skeleton } from "@/components/ui/skeleton";
import { DocumentSensitiveBadge, DocumentStatusBadge } from "@/features/documents/document-badges";
import { toDocumentErrorKey } from "@/features/documents/document-errors";
import { attachDocument } from "@/services/document-relations";
import { documentQueryKeys, getDocuments } from "@/services/documents";
import type { DocumentRelationType } from "@/types/document-relation";

/**
 * Attach a Document the office already holds to this record (M5.3, D-118).
 *
 * **The mirror of `DocumentAttachDialog`.** That one starts from a document and
 * picks a record; this one starts from a record and picks a document. They post to
 * the same endpoint with the same payload — the difference is only which half the
 * person already has in hand: "file this scan against the right matter" versus
 * "this matter needs the KTP we already have".
 *
 * **The candidate list is the ordinary document list**, so every row is already
 * bounded by `documents.view` and its Data Scope, and a sensitive document the
 * caller may not reach never appears (D-115). Attaching then re-authorizes on the
 * server under `documents.update`; this picker only decides what is *offered*.
 *
 * Documents already attached are **not filtered out**, deliberately: the list
 * endpoint answers "documents in this office", not "documents not yet attached
 * here", and inventing that filter client-side would mislead the moment a page
 * boundary hid one. Attaching twice answers 422 with a translated message, which
 * is the honest outcome.
 */
export function EntityDocumentPicker({
  entityType,
  entityId,
  open,
  onOpenChange,
  onAttached,
}: {
  entityType: DocumentRelationType;
  entityId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onAttached: () => void;
}) {
  return (
    <Dialog open={open} onOpenChange={(next) => (next ? undefined : onOpenChange(false))}>
      <DialogContent>
        {/* **The body mounts with the dialog and unmounts with it**, which is what
            resets the search, the selection and any error. Resetting them from an
            effect keyed on `open` is state written during commit — the
            `react-hooks/set-state-in-effect` rule — and would also show the
            previous selection for a frame on reopen. State that should start
            fresh belongs in a component that starts fresh. */}
        {open ? (
          <PickerBody
            entityType={entityType}
            entityId={entityId}
            onOpenChange={onOpenChange}
            onAttached={onAttached}
          />
        ) : null}
      </DialogContent>
    </Dialog>
  );
}

function PickerBody({
  entityType,
  entityId,
  onOpenChange,
  onAttached,
}: {
  entityType: DocumentRelationType;
  entityId: string;
  onOpenChange: (open: boolean) => void;
  onAttached: () => void;
}) {
  const t = useTranslations("documents");
  const tActions = useTranslations("actions");

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [selected, setSelected] = useState<string | null>(null);
  const [errorKey, setErrorKey] = useState<string | null>(null);

  // Debounced so typing does not fire a request per keystroke. The write happens
  // in the timer callback rather than in the effect body, which is the pattern
  // the other lists use.
  useEffect(() => {
    const timer = setTimeout(() => setSearch(searchInput.trim()), 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const candidates = useQuery({
    queryKey: documentQueryKeys.list({ page: 1, per_page: 20, search }),
    queryFn: () => getDocuments({ page: 1, per_page: 20, search }),
    placeholderData: keepPreviousData,
  });

  const mutation = useMutation({
    mutationFn: (documentId: string) =>
      attachDocument(documentId, { entity_type: entityType, entity_id: entityId }),
    onSuccess: () => {
      onAttached();
      onOpenChange(false);
    },
    onError: (error: unknown) => setErrorKey(toDocumentErrorKey(error)),
  });

  const rows = candidates.data?.data ?? [];

  return (
    <>
      <DialogHeader>
        <DialogTitle>{t("entityDocuments.attachTitle")}</DialogTitle>
        <DialogDescription>{t("entityDocuments.attachDescription")}</DialogDescription>
      </DialogHeader>

      {errorKey ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {t(`errors.${errorKey}`)}
        </p>
      ) : null}

      <div className="relative">
        <Search
          aria-hidden="true"
          className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
        />
        <Input
          className="pl-9"
          aria-label={t("picker.searchPlaceholder")}
          placeholder={t("picker.searchPlaceholder")}
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
        />
      </div>

      <div className="border-border max-h-64 overflow-y-auto rounded-md border">
        {candidates.isPending ? (
          <div className="flex flex-col gap-2 p-3" aria-busy="true" aria-live="polite">
            <span className="sr-only">{t("loading")}</span>
            <Skeleton className="h-8 w-full" />
            <Skeleton className="h-8 w-full" />
          </div>
        ) : rows.length === 0 ? (
          <p className="text-muted-foreground p-4 text-sm">{t("picker.noDocuments")}</p>
        ) : (
          <ul className="divide-border divide-y">
            {rows.map((row) => (
              <li key={row.id}>
                <button
                  type="button"
                  aria-pressed={selected === row.id}
                  onClick={() => {
                    setSelected(row.id);
                    setErrorKey(null);
                  }}
                  className={`focus-visible:ring-ring flex w-full items-center gap-3 px-3 py-2 text-left text-sm focus-visible:ring-2 focus-visible:outline-none ${
                    selected === row.id ? "bg-primary/10 text-foreground" : "hover:bg-muted/50"
                  }`}
                >
                  <span className="text-muted-foreground shrink-0 font-mono text-xs">
                    {row.document_number}
                  </span>
                  <span className="truncate">{row.title}</span>
                  <DocumentSensitiveBadge isSensitive={row.is_sensitive} />
                  <span className="ml-auto shrink-0">
                    <DocumentStatusBadge status={row.status} />
                  </span>
                </button>
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
          {mutation.isPending ? tActions("saving") : t("picker.selectDocument")}
        </Button>
      </DialogFooter>
    </>
  );
}
