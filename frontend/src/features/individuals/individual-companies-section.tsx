"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { RelationshipPeriod } from "@/features/companies/relationship-shared";
import { toIndividualErrorKey } from "@/features/individuals/individual-errors";
import { Link } from "@/i18n/navigation";
import {
  getIndividualManagementCompanies,
  getIndividualOwnershipCompanies,
  individualCompanyKeys,
} from "@/services/individual-companies";
import type {
  IndividualCompanySummary,
  IndividualManagementCompany,
  IndividualOwnershipCompany,
} from "@/types/individual-company";

/**
 * The companies a person is involved with — M2.4's relationship surfaces, read
 * from the person's side (M2.5).
 *
 * **Read-only, and there is no control here that changes anything.** No Add, no
 * End, no Edit, no Delete. Relationship management lives on the Company, where
 * D-085's add-and-close model lives, and there is no API route under
 * `individuals` to call even if a control existed. Duplicating M2.4's editing UI
 * here would be two ways to write one history.
 *
 * **The permission split survives the reversal.** Two subsections, two
 * endpoints, two independent queries: management answers to
 * `companies.management.view` and ownership to `companies.shareholders.view`,
 * exactly as they do from the Company side. A caller holding one never causes
 * the other to be fetched — the subsection is not rendered, so no request is
 * made — and neither permission is required for the other (D-083).
 *
 * **History, not a roster.** Ended involvements stay in the list, because "who
 * was the director in March" is the question legal records depend on.
 */
export function IndividualCompaniesSection({
  individualId,
  canViewManagement,
  canViewShareholders,
}: {
  individualId: string;
  canViewManagement: boolean;
  canViewShareholders: boolean;
}) {
  const t = useTranslations("individualCompanies");

  return (
    <div className="flex flex-col gap-6">
      <p className="text-muted-foreground text-sm">{t("description")}</p>

      {canViewManagement ? (
        <div className="flex flex-col gap-3">
          <h3 className="text-sm font-medium">{t("managementSection")}</h3>
          <ManagementList individualId={individualId} />
        </div>
      ) : null}

      {canViewShareholders ? (
        <div className="flex flex-col gap-3">
          <h3 className="text-sm font-medium">{t("ownershipSection")}</h3>
          <OwnershipList individualId={individualId} />
        </div>
      ) : null}
    </div>
  );
}

function ManagementList({ individualId }: { individualId: string }) {
  const t = useTranslations("individualCompanies");
  const tTypes = useTranslations("companyRelationships");
  const tActions = useTranslations("actions");

  const query = useQuery({
    queryKey: individualCompanyKeys.management(individualId),
    queryFn: () => getIndividualManagementCompanies(individualId),
    // A 403 is an ordinary outcome for a caller whose scope does not reach this
    // person's Office, so it is not retried into a spinner.
    retry: false,
  });

  if (query.isPending) {
    return <Skeleton className="h-24 w-full" />;
  }

  if (query.isError) {
    return (
      <BaseErrorState
        title={t("managementErrorTitle")}
        description={t(`errors.${toIndividualErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  if (query.data.length === 0) {
    return <p className="text-muted-foreground text-sm">{t("managementEmpty")}</p>;
  }

  return (
    <ul className="flex flex-col gap-3">
      {query.data.map((relationship: IndividualManagementCompany) => (
        <li
          key={relationship.id}
          className="border-border flex flex-col gap-1 rounded-md border p-3"
        >
          <CompanyName company={relationship.company} />
          <span className="text-sm">
            {tTypes(`types.${relationship.relationship_type}`)}
            {relationship.position_name ? ` · ${relationship.position_name}` : ""}
          </span>
          <RelationshipPeriod
            effectiveFrom={relationship.effective_from}
            effectiveUntil={relationship.effective_until}
            isCurrent={relationship.is_current}
          />
          <StateBadge isCurrent={relationship.is_current} />
        </li>
      ))}
    </ul>
  );
}

function OwnershipList({ individualId }: { individualId: string }) {
  const t = useTranslations("individualCompanies");
  const tTypes = useTranslations("companyRelationships");
  const tActions = useTranslations("actions");

  const query = useQuery({
    queryKey: individualCompanyKeys.shareholders(individualId),
    queryFn: () => getIndividualOwnershipCompanies(individualId),
    retry: false,
  });

  if (query.isPending) {
    return <Skeleton className="h-24 w-full" />;
  }

  if (query.isError) {
    return (
      <BaseErrorState
        title={t("ownershipErrorTitle")}
        description={t(`errors.${toIndividualErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  if (query.data.length === 0) {
    return <p className="text-muted-foreground text-sm">{t("ownershipEmpty")}</p>;
  }

  return (
    <ul className="flex flex-col gap-3">
      {query.data.map((relationship: IndividualOwnershipCompany) => (
        <li
          key={relationship.id}
          className="border-border flex flex-col gap-1 rounded-md border p-3"
        >
          <CompanyName company={relationship.company} />
          <span className="text-sm">
            {tTypes(`types.${relationship.relationship_type}`)}
            {relationship.ownership_percentage === null
              ? ""
              : ` · ${relationship.ownership_percentage}%`}
          </span>
          <RelationshipPeriod
            effectiveFrom={relationship.effective_from}
            effectiveUntil={relationship.effective_until}
            isCurrent={relationship.is_current}
          />
          <StateBadge isCurrent={relationship.is_current} />
        </li>
      ))}
    </ul>
  );
}

/**
 * The organization, named and — only sometimes — linked.
 *
 * **Linkability comes from the backend, never from a permission code.**
 * `can_view_company` is computed from the real Company policy with the caller's
 * Data Scope applied, so it answers the question a code cannot: whether *this*
 * company, in *its* Office, would actually open. An archived company is named
 * but not linked either, because its detail surface answers 404 by design.
 *
 * A company the caller cannot open is still named. The person's history is about
 * it, and hiding the name would misrepresent the record — it just does not
 * become a door.
 */
function CompanyName({ company }: { company: IndividualCompanySummary | null }) {
  const t = useTranslations("individualCompanies");

  if (company === null) {
    return <span className="text-muted-foreground text-sm">{t("unknownCompany")}</span>;
  }

  const name = company.display_name ?? t("unknownCompany");

  return (
    <span className="flex flex-wrap items-center gap-2">
      {company.can_view_company && !company.is_archived ? (
        <Link
          href={`/parties/companies/${company.id}`}
          className="font-medium underline-offset-4 hover:underline"
        >
          {name}
        </Link>
      ) : (
        <span className="font-medium">{name}</span>
      )}

      {company.is_archived ? (
        <span className="border-border text-muted-foreground rounded-full border px-2 py-0.5 text-xs">
          {t("archivedCompany")}
        </span>
      ) : null}
    </span>
  );
}

/**
 * Current or ended, and nothing inferred.
 *
 * "Current" means exactly what the schema says it means — no end date. Nothing
 * here compares a date to today or reads anything into a future start.
 */
function StateBadge({ isCurrent }: { isCurrent: boolean }) {
  const t = useTranslations("individualCompanies");

  return (
    <span className="text-muted-foreground text-xs">{isCurrent ? t("current") : t("ended")}</span>
  );
}
