"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Paperclip, Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { hasFieldError, toWarkahErrorKey } from "@/features/warkah/warkah-errors";
import { Link } from "@/i18n/navigation";
import { documentQueryKeys, getDocuments } from "@/services/documents";
import { attachWarkahDocument, detachWarkahDocument, warkahKeys } from "@/services/warkah";
import type { WarkahItem } from "@/types/warkah";

/**
 * The Documents filed against one line of a Warkah (M7.4, D-121).
 *
 * ## Attaching is what moves completeness, and it is its own capability
 *
 * `ppat.warkah.upload` — separate from `ppat.warkah.update`, which composes the
 * checklist. Writing down *which* documents a transaction needs is a different job from
 * producing them, and an office that grants one without the other is saying something
 * real.
 *
 * Detaching answers to the same code: there is no `ppat.warkah.detach` in the
 * catalogue, and removing a file misfiled against the wrong line is the correction of
 * the upload rather than a new act.
 *
 * ## Attaching is not reading
 *
 * The picker draws from `GET /api/v1/documents`, scoped by `documents.view` on its own
 * endpoint, so it can never offer a file the caller could not already open — and the
 * backend resolves the choice through the same visibility again.
 *
 * Attaching confers nothing onward: opening the file still answers to `documents.view`
 * and downloading to `documents.download`, each with its own Data Scope. A sensitive one
 * additionally answers to `documents.sensitive.download`, which has authorized a real
 * download since M8.1 closed D-115. **A Warkah capability is never a way past any of
 * those**, which is why each row links to the document surface rather than offering a
 * download here.
 *
 * `is_sensitive` is marked so a reader knows before opening.
 */
export function WarkahItemDocuments({ deedId, item }: { deedId: string; item: WarkahItem }) {
  const t = useTranslations("warkah");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [documentId, setDocumentId] = useState("");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => setSearch(searchInput.trim()), 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const candidateQuery = { page: 1, per_page: 20, search, status: "" as const };

  const candidates = useQuery({
    queryKey: documentQueryKeys.list(candidateQuery),
    queryFn: () => getDocuments(candidateQuery),
    enabled: open,
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: warkahKeys.items(deedId) });
    await queryClient.invalidateQueries({ queryKey: warkahKeys.forDeed(deedId) });
    await queryClient.invalidateQueries({ queryKey: warkahKeys.all() });
  };

  const attach = useMutation({
    mutationFn: () => attachWarkahDocument(deedId, item.id, documentId),
    onSuccess: async () => {
      setError(null);
      setOpen(false);
      setDocumentId("");
      setSearchInput("");
      await invalidate();
    },
    onError: (failure: unknown) => {
      setError(
        hasFieldError(failure, "document_id")
          ? t("validation.documentUnavailable")
          : t(`errors.${toWarkahErrorKey(failure)}`),
      );
    },
  });

  const detach = useMutation({
    mutationFn: (id: string) => detachWarkahDocument(deedId, item.id, id),
    onSuccess: async () => {
      setError(null);
      await invalidate();
    },
    onError: (failure: unknown) => setError(t(`errors.${toWarkahErrorKey(failure)}`)),
  });

  return (
    <div className="flex flex-col gap-2">
      {error ? (
        <p role="alert" className="text-destructive text-sm">
          {error}
        </p>
      ) : null}

      {item.documents.length === 0 ? (
        <p className="text-muted-foreground text-xs">{t("noDocuments")}</p>
      ) : (
        <ul className="flex flex-col gap-1">
          {item.documents.map((document) => (
            <li
              key={document.id}
              className="border-border flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
            >
              <div className="flex flex-wrap items-center gap-2">
                {/*
                  A link to the document surface, never a download here. Opening and
                  downloading are their own capabilities with their own Data Scopes,
                  and a sensitive file additionally answers to
                  `documents.sensitive.download`.
                */}
                <Link
                  href={`/documents/${document.id}`}
                  className="text-sm underline-offset-4 hover:underline"
                >
                  {document.title}
                </Link>

                {document.is_sensitive ? (
                  <span className="border-border text-muted-foreground rounded-full border px-2 py-0.5 text-xs">
                    {t("sensitive")}
                  </span>
                ) : null}
              </div>

              {item.can_upload ? (
                <Button
                  variant="ghost"
                  size="sm"
                  aria-label={t("detach")}
                  disabled={detach.isPending}
                  onClick={() => {
                    setError(null);

                    if (window.confirm(t("detachConfirm"))) {
                      detach.mutate(document.id);
                    }
                  }}
                >
                  <Trash2 aria-hidden="true" className="size-4" />
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
      )}

      {item.can_upload && !open ? (
        <div>
          <Button variant="ghost" size="sm" className="gap-2" onClick={() => setOpen(true)}>
            <Paperclip aria-hidden="true" className="size-4" />
            {t("attachDocument")}
          </Button>
        </div>
      ) : null}

      {open ? (
        <form
          className="border-border flex flex-col gap-3 rounded-md border p-3"
          onSubmit={(event) => {
            event.preventDefault();
            setError(null);

            if (documentId !== "") {
              attach.mutate();
            }
          }}
        >
          <div className="flex flex-col gap-2">
            <Label htmlFor={`doc-search-${item.id}`}>{t("findDocument")}</Label>
            <Input
              id={`doc-search-${item.id}`}
              placeholder={t("findDocumentPlaceholder")}
              value={searchInput}
              onChange={(event) => setSearchInput(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor={`doc-${item.id}`}>{t("document")}</Label>
            <select
              id={`doc-${item.id}`}
              className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
              value={documentId}
              onChange={(event) => setDocumentId(event.target.value)}
            >
              <option value="">{t("selectDocument")}</option>
              {(candidates.data?.data ?? []).map((document) => (
                <option key={document.id} value={document.id}>
                  {document.title}
                </option>
              ))}
            </select>
          </div>

          <div className="flex gap-2">
            <Button type="submit" size="sm" disabled={documentId === "" || attach.isPending}>
              {attach.isPending ? tActions("saving") : t("attachDocument")}
            </Button>
            <Button type="button" variant="outline" size="sm" onClick={() => setOpen(false)}>
              {tActions("cancel")}
            </Button>
          </div>
        </form>
      ) : null}
    </div>
  );
}
