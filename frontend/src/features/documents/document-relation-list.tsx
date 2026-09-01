"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, X } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { DocumentAttachDialog } from "@/features/documents/document-attach-dialog";
import { toDocumentErrorKey } from "@/features/documents/document-errors";
import { Link } from "@/i18n/navigation";
import {
  detachDocument,
  documentRelationKeys,
  getDocumentRelations,
} from "@/services/document-relations";
import { documentQueryKeys } from "@/services/documents";
import type { DocumentRelation, DocumentRelationType } from "@/types/document-relation";

/**
 * What a Document is attached to, with attach and detach (M5.3, D-118).
 *
 * **Gated on `can_update`**, which is the flag the backend computes from the real
 * Policy — attaching answers to `documents.update`, because it is a correction to
 * a document's own filing rather than a new capability. Reading the list answers
 * to `documents.view`, which the caller already holds to be on this page.
 *
 * **A stub links out; it never embeds.** A Party, Project or Matter the caller
 * cannot open still appears, because hiding it would make the list lie about where
 * the document sits — following the link is that surface's own decision, and it
 * answers honestly.
 *
 * Detach is confirmed in place rather than through a second dialog: it removes a
 * relationship, not a record, and the document and the target both survive
 * untouched. A modal for that would be ceremony.
 */
export function DocumentRelationList({
  documentId,
  canAttach,
}: {
  documentId: string;
  canAttach: boolean;
}) {
  const t = useTranslations("documents");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [errorKey, setErrorKey] = useState<string | null>(null);
  const [pending, setPending] = useState<string | null>(null);

  const query = useQuery({
    queryKey: documentRelationKeys.all(documentId),
    queryFn: () => getDocumentRelations(documentId),
  });

  const detach = useMutation({
    mutationFn: (relation: DocumentRelation) =>
      detachDocument(documentId, {
        entity_type: relation.entity_type,
        entity_id: relation.id,
      }),
    onMutate: (relation) => {
      setErrorKey(null);
      setPending(relation.id);
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: documentQueryKeys.all() });
    },
    onError: (error: unknown) => setErrorKey(toDocumentErrorKey(error)),
    onSettled: () => setPending(null),
  });

  const relations = query.data;

  const all: DocumentRelation[] = relations
    ? [...relations.parties, ...relations.projects, ...relations.matters]
    : [];

  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">{t("relations.title")}</h2>

        {canAttach ? (
          <Button
            variant="outline"
            size="sm"
            className="gap-2"
            onClick={() => {
              setErrorKey(null);
              setDialogOpen(true);
            }}
          >
            <Plus aria-hidden="true" className="size-4" />
            {t("relations.attach")}
          </Button>
        ) : null}
      </div>

      {errorKey ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {t(`errors.${errorKey}`)}
        </p>
      ) : null}

      {query.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          <Skeleton className="h-11 w-full" />
          <Skeleton className="h-11 w-full" />
        </div>
      ) : query.isError ? (
        <BaseErrorState
          title={t("errorTitle")}
          description={t(`errors.${toDocumentErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : all.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("relations.noRelations")}</p>
      ) : (
        <ul className="border-border divide-border divide-y rounded-lg border text-sm">
          {all.map((relation) => (
            <li
              key={`${relation.entity_type}-${relation.id}`}
              className="flex flex-wrap items-center gap-3 px-4 py-3"
            >
              <Badge className="shrink-0">{t(`relations.types.${relation.entity_type}`)}</Badge>

              {relation.reference ? (
                <span className="text-muted-foreground shrink-0 font-mono text-xs">
                  {relation.reference}
                </span>
              ) : null}

              <Link
                href={relationHref(relation)}
                className="min-w-0 truncate font-medium underline-offset-4 hover:underline"
              >
                {relation.label}
              </Link>

              {canAttach ? (
                <Button
                  variant="ghost"
                  size="sm"
                  className="ml-auto gap-1.5"
                  aria-label={`${t("relations.detach")}: ${relation.label}`}
                  disabled={pending === relation.id}
                  onClick={() => detach.mutate(relation)}
                >
                  <X aria-hidden="true" className="size-4" />
                  {t("relations.detach")}
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
      )}

      <DocumentAttachDialog
        documentId={documentId}
        open={dialogOpen}
        onOpenChange={setDialogOpen}
      />
    </section>
  );
}

/**
 * Where a stub links to.
 *
 * A Matter's surface is chosen by its own `domain`, which the API sends for
 * exactly this reason — the interface never guesses a domain, and never sends one
 * back.
 */
function relationHref(relation: DocumentRelation): string {
  const routes: Record<DocumentRelationType, string> = {
    party:
      relation.party_type === "COMPANY"
        ? `/parties/companies/${relation.id}`
        : `/parties/individuals/${relation.id}`,
    project: `/projects/${relation.id}`,
    matter:
      relation.domain === "PPAT"
        ? `/ppat/matters/${relation.id}`
        : `/notary/matters/${relation.id}`,
  };

  return routes[relation.entity_type];
}
