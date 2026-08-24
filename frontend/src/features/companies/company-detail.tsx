"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Skeleton } from "@/components/ui/skeleton";
import { useCurrentUser } from "@/features/auth/use-current-user";
import { CompanyIdentitySection } from "@/features/companies/company-identity-section";
import { CompanyManagementSection } from "@/features/companies/company-management-section";
import { CompanyShareholdersSection } from "@/features/companies/company-shareholders-section";
import { DocumentRelationSection } from "@/features/documents/document-relation-section";
import { toCompanyErrorKey } from "@/features/companies/company-errors";
import { Link, useRouter } from "@/i18n/navigation";
import { can } from "@/lib/permissions/can";
import { archiveCompany, companyQueryKeys, getCompany } from "@/services/companies";

/**
 * One Company.
 *
 * Two sections, and only two: **Overview** and **Identity**. There is
 * deliberately no Management, Shareholders, Documents, Projects, Matters, or
 * Timeline tab — M2.4 owns company relationships and the rest do not exist, and
 * an empty tab is a promise the product cannot keep (D-064). The backend sends
 * no relationship data either, so nothing here could render one.
 *
 * The Identity section is a separate component with its own query, so a caller
 * who may view the record but not its tax identity simply does not load it — the
 * overview still renders, and the missing section is a normal condition rather
 * than an error.
 */
export function CompanyDetail({ companyId }: { companyId: string }) {
  const t = useTranslations("companies");
  const tRelationships = useTranslations("companyRelationships");
  const tActions = useTranslations("actions");

  const { data: user } = useCurrentUser();

  const query = useQuery({
    queryKey: companyQueryKeys.detail(companyId),
    queryFn: () => getCompany(companyId),
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-3" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        {[0, 1].map((row) => (
          <Skeleton key={row} className="h-40 w-full" />
        ))}
      </div>
    );
  }

  if (query.isError || !query.data) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toCompanyErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  const company = query.data;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold tracking-tight">
            {company.display_name ?? company.legal_name}
          </h1>
          <p className="text-muted-foreground text-sm">
            {company.office ? `${company.office.code} — ${company.office.name}` : "—"}
          </p>
        </div>

        <div className="flex gap-2">
          <PermissionGuard permission="companies.update">
            <Button
              variant="outline"
              render={<Link href={`/parties/companies/${company.id}/edit`} />}
            >
              {t("editAction")}
            </Button>
          </PermissionGuard>

          <PermissionGuard permission="companies.archive">
            <ArchiveButton companyId={company.id} />
          </PermissionGuard>
        </div>
      </div>

      <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
        <div className="flex flex-col gap-1">
          <h2 className="text-base font-medium">{t("overviewSection")}</h2>
          <p className="text-muted-foreground text-sm">{t("overviewDescription")}</p>
        </div>

        <dl className="grid gap-4 sm:grid-cols-2">
          <Detail label={t("legalNameLabel")} value={company.legal_name} />
          <Detail label={t("shortNameLabel")} value={company.short_name} />
          <Detail
            label={t("entityTypeLabel")}
            value={company.entity_type ? t(`entityTypes.${company.entity_type}`) : null}
          />
          <Detail label={t("registrationNumberLabel")} value={company.registration_number} />
          <Detail label={t("phoneLabel")} value={company.primary_phone} />
          <Detail label={t("emailLabel")} value={company.primary_email} />
          <Detail label={t("addressLabel")} value={company.address} />
          <Detail label={t("cityLabel")} value={company.city} />
          <Detail label={t("provinceLabel")} value={company.province} />
          <Detail label={t("postalCodeLabel")} value={company.postal_code} />
        </dl>
      </section>

      <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
        <h2 className="text-base font-medium">{t("identitySection")}</h2>

        <CompanyIdentitySection
          companyId={company.id}
          canUpdate={can(user, "parties.identity.update")}
        />
      </section>

      {/*
        Management and Shareholders are rendered only for a holder of that
        category's view permission, and each fetches its own endpoint. Neither
        appears in the Company payload, so holding one capability cannot cause
        the other's data to be requested — the permission split is the boundary,
        not the tab (D-083).
      */}
      {can(user, "companies.management.view") ? (
        <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
          <h2 className="text-base font-medium">{tRelationships("managementSection")}</h2>

          <CompanyManagementSection
            companyId={company.id}
            canUpdate={can(user, "companies.management.update")}
          />
        </section>
      ) : null}

      {can(user, "companies.shareholders.view") ? (
        <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
          <h2 className="text-base font-medium">{tRelationships("shareholdersSection")}</h2>

          <CompanyShareholdersSection
            companyId={company.id}
            canUpdate={can(user, "companies.shareholders.update")}
          />
        </section>
      ) : null}

      {/* Documents (M5.3, D-118). A section, not a tab — the pattern this page
          already uses for management and shareholders.

          **`company.id` is the Party ULID**, not the Company row's key: M2
          exposes one public identifier for the aggregate (D-078), and
          `party_documents.party_id` references `parties.id`. */}
      <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
        <DocumentRelationSection
          filter={{ party_id: company.id }}
          uploadHref="/documents/upload"
          attachTo={{ entity_type: "party", entity_id: company.id }}
        />
      </section>
    </div>
  );
}

function Detail({ label, value }: { label: string; value: string | null }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-sm font-medium">{label}</dt>
      <dd className="text-muted-foreground text-sm">{value ?? "—"}</dd>
    </div>
  );
}

/**
 * Archive, behind a confirmation.
 *
 * The wording describes the operational consequence and says the record is
 * retained rather than deleted. Claiming a legal deletion would be false: other
 * records may refer to this one, relationship history is kept, and Party-domain
 * data is master data (D-081).
 */
function ArchiveButton({ companyId }: { companyId: string }) {
  const t = useTranslations("companies");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => archiveCompany(companyId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: companyQueryKeys.all });
      setOpen(false);
      router.push("/parties/companies");
    },
    onError: (error: unknown) => setErrorKey(toCompanyErrorKey(error)),
  });

  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        {t("archiveAction")}
      </Button>

      <Dialog open={open} onOpenChange={(next) => (next ? undefined : setOpen(false))}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("archiveTitle")}</DialogTitle>
            <DialogDescription>{t("archiveDescription")}</DialogDescription>
          </DialogHeader>

          {errorKey ? (
            <p
              role="alert"
              className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
            >
              {t(`errors.${errorKey}`)}
            </p>
          ) : null}

          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              {tActions("cancel")}
            </Button>
            <Button disabled={mutation.isPending} onClick={() => mutation.mutate()}>
              {mutation.isPending ? tActions("saving") : t("archiveConfirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
