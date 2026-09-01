"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link2, Plus } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { ButtonLink } from "@/components/ui/button-link";
import { Skeleton } from "@/components/ui/skeleton";
import { DocumentSensitiveBadge, DocumentStatusBadge } from "@/features/documents/document-badges";
import { toDocumentErrorKey } from "@/features/documents/document-errors";
import { EntityDocumentPicker } from "@/features/documents/entity-document-picker";
import { Link } from "@/i18n/navigation";
import { documentQueryKeys, getDocuments } from "@/services/documents";
import type { DocumentListQuery } from "@/types/document";
import type { DocumentRelationType } from "@/types/document-relation";

/**
 * The documents attached to one Project or Matter.
 *
 * **A section, not a tab** — the M4.5 and M4.7 precedent on these same two pages,
 * and the M5 lock's own ruling: the repository has no `Tabs` primitive, and adding
 * one is a design decision affecting pages M4 already shipped rather than a side
 * effect of a document milestone.
 *
 * **It answers to its own capability and its own endpoint.** Reading the documents
 * attached to a Matter requires `documents.view` at a usable scope, which is a
 * separate question from reaching the Matter — so this is deliberately not folded
 * into the Matter resource, where it would have made `notary.matters.view` a way
 * to read what has been filed. An actor who may open the Matter and not its
 * documents sees the section fail honestly rather than see a fabricated empty one.
 *
 * **The filter is applied server-side.** The list endpoint narrows by relation
 * inside the visibility-scoped query, so this component never fetches everything
 * and discards; the count it shows is what that caller may see.
 */
export function DocumentRelationSection({
  filter,
  uploadHref,
  attachTo,
}: {
  filter: Pick<DocumentListQuery, "party_id" | "project_id" | "matter_id">;
  uploadHref?: string;
  /**
   * The record this section belongs to (M5.3). Supplying it turns on "attach an
   * existing document", which is the other half of filing: uploading files a new
   * one, attaching connects a document the office already holds.
   */
  attachTo?: { entity_type: DocumentRelationType; entity_id: string };
}) {
  const t = useTranslations("documents");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [pickerOpen, setPickerOpen] = useState(false);

  const query = useQuery({
    queryKey: documentQueryKeys.list({ ...filter, per_page: 50 }),
    queryFn: () => getDocuments({ ...filter, per_page: 50 }),
  });

  const documents = query.data?.data ?? [];

  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">{t("sectionTitle")}</h2>

        <div className="flex flex-wrap gap-2">
          {/* Attaching answers to `documents.update` — a correction to a
              document's own filing — while uploading answers to
              `documents.upload`. Two capabilities, two buttons, and the guard on
              each names its own. */}
          {attachTo ? (
            <PermissionGuard permission="documents.update">
              <Button
                variant="outline"
                size="sm"
                className="gap-2"
                onClick={() => setPickerOpen(true)}
              >
                <Link2 aria-hidden="true" className="size-4" />
                {t("entityDocuments.attachDocument")}
              </Button>
            </PermissionGuard>
          ) : null}

          {uploadHref ? (
            <PermissionGuard permission="documents.upload">
              <ButtonLink variant="outline" size="sm" className="gap-2" href={uploadHref}>
                <Plus aria-hidden="true" className="size-4" />
                {t("upload")}
              </ButtonLink>
            </PermissionGuard>
          ) : null}
        </div>
      </div>

      {query.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
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
      ) : documents.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("noAttached")}</p>
      ) : (
        <ul className="border-border divide-border divide-y rounded-lg border text-sm">
          {documents.map((document) => (
            <li
              key={document.id}
              className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
            >
              <div className="flex min-w-0 flex-wrap items-center gap-2">
                <span className="text-muted-foreground font-mono text-xs">
                  {document.document_number}
                </span>
                <Link
                  href={`/documents/${document.id}`}
                  className="truncate font-medium underline-offset-4 hover:underline"
                >
                  {document.title}
                </Link>
                <DocumentSensitiveBadge isSensitive={document.is_sensitive} />
              </div>

              <DocumentStatusBadge status={document.status} />
            </li>
          ))}
        </ul>
      )}

      {attachTo ? (
        <EntityDocumentPicker
          entityType={attachTo.entity_type}
          entityId={attachTo.entity_id}
          open={pickerOpen}
          onOpenChange={setPickerOpen}
          onAttached={() => {
            void queryClient.invalidateQueries({ queryKey: documentQueryKeys.all() });
          }}
        />
      ) : null}
    </section>
  );
}
