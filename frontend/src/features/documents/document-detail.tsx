"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Download, Pencil } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { DocumentSensitiveBadge, DocumentStatusBadge } from "@/features/documents/document-badges";
import { toDocumentErrorKey } from "@/features/documents/document-errors";
import { DocumentVersionList } from "@/features/documents/document-version-list";
import { Link, useRouter } from "@/i18n/navigation";
import {
  archiveDocument,
  deleteDocument,
  documentQueryKeys,
  downloadDocument,
  getDocument,
  verifyDocument,
} from "@/services/documents";
// Aliased because `Document` is a DOM global: an unaliased import shadows it in
// this file, and this component legitimately uses both.
import type { Document as OfficeDocument } from "@/types/document";

/**
 * One Document, and the acts its capabilities allow.
 *
 * **Every control is gated on a backend-computed flag**, not on a permission
 * string the browser assembled: `can_update`, `can_download`, `can_verify`,
 * `can_archive` and `can_delete` come from the real Policy, so the interface asks
 * exactly the question the server will ask. They decide what is *offered*; each
 * endpoint authorizes again, and a client that lies to itself about them gains
 * nothing (D-113).
 *
 * **The flags fold in status eligibility as well as capability**, which is why
 * there is no separate check here: `can_verify` is false on a document that is
 * already verified, so the button is absent rather than present-and-doomed. A
 * control that offers something the endpoint answers 422 to is worse than no
 * control.
 *
 * **There is no status dropdown**, and its absence reflects the canonical registry
 * rather than an omission. The two acts that change a status are `verify` and
 * `archive`, each its own capability and its own button; `UNDER_REVIEW`, `FINAL`
 * and `VOID` cannot be set by anybody (D-117), so offering a dropdown that
 * silently failed for three of its seven options would be dishonest.
 *
 * **`can_download` is false for every sensitive document**, whatever the actor
 * holds, because no sensitive-download surface ships before an audit store exists
 * (D-115). The interface says so in place of the button rather than leaving
 * somebody to guess why it is missing.
 */
export function DocumentDetail({ documentId }: { documentId: string }) {
  const t = useTranslations("documents");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();
  const router = useRouter();

  const [actionError, setActionError] = useState<string | null>(null);

  const query = useQuery({
    queryKey: documentQueryKeys.detail(documentId),
    queryFn: () => getDocument(documentId),
  });

  const document = query.data;

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: documentQueryKeys.all() });
  };

  const onActionError = (error: unknown) => {
    setActionError(t(`errors.${toDocumentErrorKey(error)}`));
  };

  const verify = useMutation({
    mutationFn: () => verifyDocument(documentId),
    onSuccess: invalidate,
    onError: onActionError,
  });

  const archive = useMutation({
    mutationFn: () => archiveDocument(documentId),
    onSuccess: invalidate,
    onError: onActionError,
  });

  const remove = useMutation({
    mutationFn: () => deleteDocument(documentId),
    onSuccess: async () => {
      await invalidate();
      router.push("/documents");
    },
    onError: onActionError,
  });

  const download = useMutation({
    mutationFn: () =>
      downloadDocument(
        documentId,
        document?.current_version?.original_filename ??
          `${document?.document_number ?? "document"}`,
      ),
    onError: onActionError,
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

  if (query.isError || !document) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toDocumentErrorKey(query.error)}`)}
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
          <h1 className="text-2xl font-semibold tracking-tight">{document.title}</h1>
          <DocumentStatusBadge status={document.status} />
          <DocumentSensitiveBadge isSensitive={document.is_sensitive} />
        </div>

        <p className="text-muted-foreground font-mono text-sm">{document.document_number}</p>

        {actionError ? (
          <p
            role="alert"
            className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
          >
            {actionError}
          </p>
        ) : null}

        <div className="flex flex-wrap gap-2">
          {document.can_download ? (
            <Button
              variant="outline"
              className="gap-2"
              disabled={download.isPending}
              onClick={() => {
                setActionError(null);
                download.mutate();
              }}
            >
              <Download aria-hidden="true" className="size-4" />
              {download.isPending ? t("downloading") : t("download")}
            </Button>
          ) : document.is_sensitive ? (
            <p className="text-muted-foreground text-sm">{t("sensitiveDownloadUnavailable")}</p>
          ) : null}

          {document.can_update ? (
            <Button
              variant="outline"
              className="gap-2"
              render={<Link href={`/documents/${document.id}/edit`} />}
            >
              <Pencil aria-hidden="true" className="size-4" />
              {tActions("edit")}
            </Button>
          ) : null}

          {document.can_verify ? (
            <Button
              variant="outline"
              disabled={verify.isPending}
              onClick={() => {
                setActionError(null);
                verify.mutate();
              }}
            >
              {t("verify")}
            </Button>
          ) : null}

          {document.can_archive ? (
            <Button
              variant="outline"
              disabled={archive.isPending}
              onClick={() => {
                setActionError(null);
                archive.mutate();
              }}
            >
              {t("archive")}
            </Button>
          ) : null}

          {document.can_delete ? (
            <Button
              variant="outline"
              disabled={remove.isPending}
              onClick={() => {
                setActionError(null);

                // A deletion the office cannot undo through the product — there is
                // no restore endpoint — so it is confirmed rather than one click.
                if (window.confirm(t("deleteConfirm"))) {
                  remove.mutate();
                }
              }}
            >
              {t("delete")}
            </Button>
          ) : null}
        </div>
      </header>

      <section className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t("metadataTitle")}</h2>

        <dl className="border-border grid gap-x-8 gap-y-3 rounded-lg border p-4 sm:grid-cols-2">
          <Detail label={t("typeLabel")} value={document.document_type_code ?? "—"} />
          <Detail label={t("documentDateLabel")} value={document.document_date ?? "—"} />
          <Detail label={t("expiryDateLabel")} value={document.expiry_date ?? "—"} />
          <Detail label={t("uploadedByLabel")} value={document.created_by?.name ?? "—"} />
          <Detail label={t("officeLabel")} value={document.office?.name ?? "—"} />
          <Detail label={t("archivedAtLabel")} value={document.archived_at?.slice(0, 10) ?? "—"} />
          {document.notes ? (
            <div className="flex flex-col gap-1 sm:col-span-2">
              <dt className="text-sm font-medium">{t("notesLabel")}</dt>
              <dd className="text-muted-foreground text-sm whitespace-pre-line">
                {document.notes}
              </dd>
            </div>
          ) : null}
        </dl>
      </section>

      <DocumentVersionList
        versions={document.versions ?? []}
        canDownload={document.can_download}
        onDownload={() => {
          setActionError(null);
          download.mutate();
        }}
        isDownloading={download.isPending}
      />

      <RelatedRecords document={document} />
    </div>
  );
}

/**
 * What the document is attached to.
 *
 * Stubs and links, never embedded records: opening a Project is the Project
 * surface's job, and it authorizes for itself. A caller who cannot reach one of
 * these follows the link and gets the honest answer there.
 */
function RelatedRecords({ document }: { document: OfficeDocument }) {
  const t = useTranslations("documents");

  const parties = document.related.parties ?? [];
  const projects = document.related.projects ?? [];
  const matters = document.related.matters ?? [];

  if (parties.length === 0 && projects.length === 0 && matters.length === 0) {
    return (
      <section className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t("relatedTitle")}</h2>
        <p className="text-muted-foreground text-sm">{t("noRelated")}</p>
      </section>
    );
  }

  return (
    <section className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t("relatedTitle")}</h2>

      <ul className="border-border divide-border divide-y rounded-lg border text-sm">
        {parties.map((party) => (
          <li key={party.id} className="flex items-center justify-between gap-3 px-4 py-3">
            <span>{party.display_name}</span>
            <Link
              href={
                party.party_type === "INDIVIDUAL"
                  ? `/parties/individuals/${party.id}`
                  : `/parties/companies/${party.id}`
              }
              className="text-muted-foreground text-xs underline-offset-4 hover:underline"
            >
              {t("partyLabel")}
            </Link>
          </li>
        ))}

        {projects.map((project) => (
          <li key={project.id} className="flex items-center justify-between gap-3 px-4 py-3">
            <span>
              <span className="text-muted-foreground font-mono text-xs">
                {project.project_number}
              </span>{" "}
              {project.title}
            </span>
            <Link
              href={`/projects/${project.id}`}
              className="text-muted-foreground text-xs underline-offset-4 hover:underline"
            >
              {t("projectLabel")}
            </Link>
          </li>
        ))}

        {matters.map((matter) => (
          <li key={matter.id} className="flex items-center justify-between gap-3 px-4 py-3">
            <span>
              <span className="text-muted-foreground font-mono text-xs">
                {matter.matter_number}
              </span>{" "}
              {matter.title}
            </span>
            <Link
              href={
                matter.domain === "NOTARY"
                  ? `/notary/matters/${matter.id}`
                  : `/ppat/matters/${matter.id}`
              }
              className="text-muted-foreground text-xs underline-offset-4 hover:underline"
            >
              {t("matterLabel")}
            </Link>
          </li>
        ))}
      </ul>
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
