"use client";

import { Download } from "lucide-react";
import { useTranslations } from "next-intl";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import type { DocumentVersion } from "@/types/document";

/**
 * Every version of a Document, newest first.
 *
 * **Only the current version can be downloaded**, and that is a backend fact
 * rather than an interface choice: the download endpoint follows
 * `documents.current_version_id` and takes no version parameter. Offering a button
 * per row would suggest a per-version download the API does not have, so older
 * rows show what they are and nothing more.
 *
 * **No storage path, no stored filename, no checksum** — the API never sends any
 * of them (D-114), and the type has no field for one. What a person needs to tell
 * two versions apart is the original name, the size, and who uploaded it when.
 *
 * A version is written once and never overwritten (`CLAUDE.md` section 19), so
 * there is no edit control here and never will be: a correction is a new version.
 */
export function DocumentVersionList({
  versions,
  canDownload,
  onDownload,
  isDownloading,
}: {
  versions: DocumentVersion[];
  canDownload: boolean;
  onDownload: () => void;
  isDownloading: boolean;
}) {
  const t = useTranslations("documents");

  return (
    <section className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t("versionsTitle")}</h2>

      {versions.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("noVersions")}</p>
      ) : (
        <div className="border-border overflow-x-auto rounded-lg border">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">{t("versionsCaption")}</caption>
            <thead className="bg-muted/40 text-muted-foreground">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("versionLabel")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("filenameLabel")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium sm:table-cell">
                  {t("sizeLabel")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("uploadedByLabel")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("uploadedAtLabel")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  <span className="sr-only">{t("actionsLabel")}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {versions.map((version) => (
                <tr key={version.id} className="border-border border-t">
                  <td className="px-4 py-3 whitespace-nowrap">
                    <span className="font-mono text-xs">v{version.version_number}</span>
                    {version.is_current ? (
                      <Badge tone="primarySubtle" className="ml-2">
                        {t("currentVersion")}
                      </Badge>
                    ) : null}
                  </td>
                  <td className="max-w-xs truncate px-4 py-3">{version.original_filename}</td>
                  <td className="text-muted-foreground hidden px-4 py-3 whitespace-nowrap sm:table-cell">
                    {formatBytes(version.file_size)}
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
                    {version.uploaded_by?.name ?? "—"}
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 whitespace-nowrap lg:table-cell">
                    {version.uploaded_at?.slice(0, 10) ?? "—"}
                  </td>
                  <td className="px-4 py-3 text-right">
                    {version.is_current && canDownload ? (
                      <Button
                        variant="ghost"
                        size="sm"
                        className="gap-2"
                        disabled={isDownloading}
                        onClick={onDownload}
                      >
                        <Download aria-hidden="true" className="size-4" />
                        {t("download")}
                      </Button>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`;
  }

  if (bytes < 1024 * 1024) {
    return `${Math.round(bytes / 1024)} KB`;
  }

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
