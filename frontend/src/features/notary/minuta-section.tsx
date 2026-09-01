"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AxiosError } from "axios";
import { Search } from "lucide-react";
import { useTranslations } from "next-intl";

import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { hasFieldError, toNotaryErrorKey } from "@/features/notary/deed-errors";
import { Link } from "@/i18n/navigation";
import { fileMinuta, getMinuta, notaryDeedKeys, updateMinuta } from "@/services/notary";
import { documentQueryKeys, getDocuments } from "@/services/documents";
import type { NotaryMinuta } from "@/types/notary";

/**
 * Where a deed's original is filed — Minuta Akta (M6.3, D-120).
 *
 * **A section on the deed page, not a tab and not a page.** The repository has no
 * `Tabs` primitive — the ruling M5.2, M5.3, M5.4 and M6.2 each made — and a Minuta has
 * no address of its own to give a page: it is one record per deed, reached only
 * through the deed.
 *
 * **A 404 is the ordinary empty state, not an error.** The endpoint answers one record
 * or nothing, and "nothing filed yet" is what most deeds look like. The section
 * renders the filing form instead of a failure, and reserves the error state for the
 * statuses that really are failures.
 *
 * **There is no delete, archive or release control**, and none is disabled either —
 * they are absent. The catalogue defines no `notary.minuta.delete` at all;
 * `notary.minuta.archive` and `notary.minuta.release` exist and are unimplemented,
 * because *"what triggers Minuta Akta archiving, and what release conditions apply?"*
 * is an open domain question. A button nobody can be granted is worse than no button.
 *
 * **Correcting a filing replaces its Document.** That is what the update form is for,
 * and it is the honest substitute for a delete: a bad scan is replaced, and both
 * Documents keep their own version histories (D-116).
 */
export function MinutaSection({ deedId }: { deedId: string }) {
  const t = useTranslations("notary");

  const query = useQuery({
    queryKey: notaryDeedKeys.minuta(deedId),
    queryFn: () => getMinuta(deedId),
    // A 404 is "nothing filed", which is a normal answer rather than a fault, so
    // there is nothing to retry.
    retry: false,
  });

  const notFiled =
    query.isError && query.error instanceof AxiosError && query.error.response?.status === 404;

  return (
    <section className="border-border flex flex-col gap-4 rounded-lg border p-4">
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-medium">{t("minuta.title")}</h3>
        <p className="text-muted-foreground text-xs">{t("minuta.hint")}</p>
      </div>

      {query.isPending ? (
        <div aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          <Skeleton className="h-24 w-full" />
        </div>
      ) : notFiled ? (
        <NotFiled deedId={deedId} />
      ) : query.isError ? (
        <p role="alert" className="text-destructive text-sm">
          {t(`errors.${toNotaryErrorKey(query.error)}`)}
        </p>
      ) : (
        <Filed deedId={deedId} minuta={query.data} />
      )}
    </section>
  );
}

/**
 * Nothing filed yet.
 *
 * The control is gated on `notary.minuta.create` — its own capability, which reading
 * the deed does not confer.
 */
function NotFiled({ deedId }: { deedId: string }) {
  const t = useTranslations("notary");
  const [filing, setFiling] = useState(false);

  if (filing) {
    return <MinutaForm deedId={deedId} onDone={() => setFiling(false)} />;
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="text-muted-foreground text-sm">{t("minuta.noMinuta")}</p>

      <PermissionGuard permission="notary.minuta.create">
        <div>
          <Button variant="outline" size="sm" onClick={() => setFiling(true)}>
            {t("minuta.attach")}
          </Button>
        </div>
      </PermissionGuard>
    </div>
  );
}

/**
 * The filing record as it stands, with the option to correct it.
 *
 * `can_update` is computed from the real Policy, so the edit control is absent for an
 * actor who may read the filing and not change it.
 */
function Filed({ deedId, minuta }: { deedId: string; minuta: NotaryMinuta }) {
  const t = useTranslations("notary");
  const [editing, setEditing] = useState(false);

  if (editing) {
    return <MinutaForm deedId={deedId} minuta={minuta} onDone={() => setEditing(false)} />;
  }

  return (
    <div className="flex flex-col gap-4">
      <dl className="grid gap-x-8 gap-y-3 sm:grid-cols-2">
        <div className="flex flex-col gap-1">
          <dt className="text-sm font-medium">{t("minuta.document")}</dt>
          <dd className="text-sm">
            {minuta.document ? (
              <Link
                href={`/documents/${minuta.document.id}`}
                className="underline-offset-4 hover:underline"
              >
                {minuta.document.title}
              </Link>
            ) : (
              <span className="text-muted-foreground">{t("minuta.noDocument")}</span>
            )}
          </dd>
        </div>

        <Detail label={t("minuta.archiveLocation")} value={minuta.archive_location} />
        <Detail label={t("minuta.volumeNumber")} value={minuta.volume_number} />
        <Detail label={t("minuta.bundleNumber")} value={minuta.bundle_number} />

        {/* Canonical columns nothing writes. Rendered as unset rather than hidden,
            so a reader can see the field exists and is empty rather than wondering
            whether it was dropped. */}
        <Detail label={t("minuta.releaseStatus")} value={minuta.release_status} />
        <Detail label={t("minuta.archivedAt")} value={minuta.archived_at?.slice(0, 10) ?? null} />
      </dl>

      {minuta.notes ? (
        <div className="flex flex-col gap-1">
          <p className="text-sm font-medium">{t("minuta.notes")}</p>
          <p className="text-muted-foreground text-sm whitespace-pre-line">{minuta.notes}</p>
        </div>
      ) : null}

      <p className="text-muted-foreground text-xs">{t("minuta.lifecycleHint")}</p>

      {minuta.can_update ? (
        <div>
          <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
            {t("minuta.update")}
          </Button>
        </div>
      ) : null}
    </div>
  );
}

/**
 * File or correct.
 *
 * **The document picker is a selection control, not `EntityDocumentPicker`.** That
 * component commits an attach to the junction endpoint the moment you choose (M5.3);
 * here the chosen id is a *column* on the filing record, submitted with the rest of
 * the form. The candidate list is the same one either way — the ordinary document
 * list, already bounded by `documents.view` and its Data Scope, so a sensitive
 * document the caller cannot reach never appears (D-115). The server re-resolves the
 * id regardless; this only decides what is offered.
 */
function MinutaForm({
  deedId,
  minuta,
  onDone,
}: {
  deedId: string;
  minuta?: NotaryMinuta;
  onDone: () => void;
}) {
  const t = useTranslations("notary");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [documentId, setDocumentId] = useState(minuta?.document?.id ?? "");
  const [search, setSearch] = useState("");
  const [archiveLocation, setArchiveLocation] = useState(minuta?.archive_location ?? "");
  const [volume, setVolume] = useState(minuta?.volume_number ?? "");
  const [bundle, setBundle] = useState(minuta?.bundle_number ?? "");
  const [error, setError] = useState<string | null>(null);

  const candidates = useQuery({
    queryKey: documentQueryKeys.list({ search, per_page: 20 }),
    queryFn: () => getDocuments({ search, per_page: 20 }),
  });

  const mutation = useMutation({
    mutationFn: () => {
      const blank = (value: string) => (value.trim() === "" ? null : value.trim());

      const payload = {
        archive_location: blank(archiveLocation),
        volume_number: blank(volume),
        bundle_number: blank(bundle),
      };

      return minuta === undefined
        ? fileMinuta(deedId, { ...payload, document_id: documentId })
        : updateMinuta(deedId, { ...payload, document_id: documentId });
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: notaryDeedKeys.minuta(deedId) });
      onDone();
    },
    onError: (failure: unknown) => {
      // The server knows what the browser cannot: whether the Document is reachable
      // and in this deed's Office, and whether a Minuta is already filed. Both answer
      // on `document_id`.
      setError(
        hasFieldError(failure, "document_id")
          ? t("minuta.documentUnavailable")
          : t(`errors.${toNotaryErrorKey(failure)}`),
      );
    },
  });

  return (
    <form
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault();
        setError(null);

        if (documentId !== "") {
          mutation.mutate();
        }
      }}
    >
      {error ? (
        <p role="alert" className="text-destructive text-sm">
          {error}
        </p>
      ) : null}

      <div className="flex flex-col gap-2">
        <Label htmlFor="minuta-search">{t("minuta.findDocument")}</Label>
        <div className="relative">
          <Search
            aria-hidden="true"
            className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
          />
          <Input
            id="minuta-search"
            className="pl-9"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t("minuta.findDocumentPlaceholder")}
          />
        </div>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="minuta-document">{t("minuta.document")}</Label>
        <Select
          id="minuta-document"
          value={documentId}
          onChange={(event) => setDocumentId(event.target.value)}
        >
          <option value="">{t("minuta.selectDocument")}</option>
          {(candidates.data?.data ?? []).map((document) => (
            <option key={document.id} value={document.id}>
              {document.title}
            </option>
          ))}
        </Select>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <Field
          id="minuta-archive-location"
          label={t("minuta.archiveLocation")}
          value={archiveLocation}
          onChange={setArchiveLocation}
        />
        <Field
          id="minuta-volume"
          label={t("minuta.volumeNumber")}
          value={volume}
          onChange={setVolume}
        />
        <Field
          id="minuta-bundle"
          label={t("minuta.bundleNumber")}
          value={bundle}
          onChange={setBundle}
        />
      </div>

      <p className="text-muted-foreground text-xs">{t("minuta.shelfHint")}</p>

      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={documentId === "" || mutation.isPending}>
          {mutation.isPending ? tActions("saving") : tActions("save")}
        </Button>
        <Button type="button" variant="outline" size="sm" onClick={onDone}>
          {tActions("cancel")}
        </Button>
      </div>
    </form>
  );
}

function Field({
  id,
  label,
  value,
  onChange,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input id={id} value={value} onChange={(event) => onChange(event.target.value)} />
    </div>
  );
}

function Detail({ label, value }: { label: string; value: string | null }) {
  const t = useTranslations("notary");

  return (
    <div className="flex flex-col gap-1">
      <dt className="text-sm font-medium">{label}</dt>
      <dd className="text-muted-foreground text-sm">{value ?? t("minuta.unset")}</dd>
    </div>
  );
}
