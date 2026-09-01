"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { toCompanyErrorKey } from "@/features/companies/company-errors";
import {
  CandidateSelect,
  EndRelationshipDialog,
  RelationshipPeriod,
  RelationshipPerson,
} from "@/features/companies/relationship-shared";
import {
  addCompanyManagement,
  companyManagementKeys,
  endCompanyManagement,
  getCompanyManagement,
  getCompanyManagementOptions,
} from "@/services/company-relationships";
import {
  MANAGEMENT_RELATIONSHIP_TYPES,
  type ManagementRelationshipType,
} from "@/types/company-relationship";

/**
 * Who acts for the Company — directors, commissioners, authorized persons.
 *
 * **Its own query, deliberately.** The section is rendered only for a holder of
 * `companies.management.view` and fetches its own endpoint, so a caller without
 * that capability never triggers the request — and the ordinary Company payload
 * carries no relationship data for it to read instead (D-083). The shareholders
 * section is entirely separate for the mirror reason: neither permission causes
 * the other's data to be fetched.
 *
 * **History, not a roster.** Ended relationships stay in the list, because "who
 * was the director in March" is the question legal records depend on. There is
 * no delete and no edit: a relationship is added or ended, and superseding one
 * is both — two rows, both readable.
 *
 * No `ownership_percentage` appears here. That belongs to the ownership surface,
 * under its own permission.
 */
export function CompanyManagementSection({
  companyId,
  canUpdate,
}: {
  companyId: string;
  canUpdate: boolean;
}) {
  const t = useTranslations("companyRelationships");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [adding, setAdding] = useState(false);
  const [endingId, setEndingId] = useState<string | null>(null);

  const query = useQuery({
    queryKey: companyManagementKeys.list(companyId),
    queryFn: () => getCompanyManagement(companyId),
    retry: false,
  });

  // Only the relationship collection is invalidated — never the Company detail,
  // and never the other category.
  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: companyManagementKeys.all(companyId) });

  if (query.isPending) {
    return <Skeleton className="h-32 w-full" />;
  }

  if (query.isError) {
    return (
      <BaseErrorState
        title={t("managementErrorTitle")}
        description={t(`errors.${toCompanyErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  const relationships = query.data;

  return (
    <div className="flex flex-col gap-4">
      <p className="text-muted-foreground text-sm">{t("managementDescription")}</p>

      {relationships.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("managementEmpty")}</p>
      ) : (
        <ul className="flex flex-col gap-3">
          {relationships.map((relationship) => (
            <li
              key={relationship.id}
              className="border-border flex flex-wrap items-start justify-between gap-3 rounded-md border p-3"
            >
              <div className="flex flex-col gap-1">
                <RelationshipPerson individual={relationship.individual} />
                <span className="text-sm">
                  {t(`types.${relationship.relationship_type}`)}
                  {relationship.position_name ? ` · ${relationship.position_name}` : ""}
                </span>
                <RelationshipPeriod
                  effectiveFrom={relationship.effective_from}
                  effectiveUntil={relationship.effective_until}
                  isCurrent={relationship.is_current}
                />
              </div>

              {canUpdate && relationship.is_current ? (
                <Button variant="outline" size="sm" onClick={() => setEndingId(relationship.id)}>
                  {t("endAction")}
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
      )}

      {canUpdate ? (
        adding ? (
          <AddManagementForm
            companyId={companyId}
            onDone={() => {
              setAdding(false);
              void refresh();
            }}
            onCancel={() => setAdding(false)}
          />
        ) : (
          <div>
            <Button variant="outline" size="sm" onClick={() => setAdding(true)}>
              {t("addManagementAction")}
            </Button>
          </div>
        )
      ) : null}

      <EndRelationshipDialog
        open={endingId !== null}
        onOpenChange={(open) => (open ? undefined : setEndingId(null))}
        onEnd={(effectiveUntil) =>
          endCompanyManagement(companyId, endingId ?? "", { effective_until: effectiveUntil })
        }
        onEnded={() => {
          setEndingId(null);
          void refresh();
        }}
      />
    </div>
  );
}

/**
 * Recording a new management relationship.
 *
 * The type choices are the three management codes and nothing else — the
 * ownership codes are not offered here, and the backend refuses them anyway.
 * Codes are submitted; the labels are translated for display only.
 */
function AddManagementForm({
  companyId,
  onDone,
  onCancel,
}: {
  companyId: string;
  onDone: () => void;
  onCancel: () => void;
}) {
  const t = useTranslations("companyRelationships");
  const tActions = useTranslations("actions");

  const [individualId, setIndividualId] = useState("");
  const [type, setType] = useState<ManagementRelationshipType>("DIRECTOR");
  const [positionName, setPositionName] = useState("");
  const [effectiveFrom, setEffectiveFrom] = useState("");
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const options = useQuery({
    queryKey: companyManagementKeys.options(companyId),
    queryFn: () => getCompanyManagementOptions(companyId),
    retry: false,
  });

  const mutation = useMutation({
    mutationFn: () =>
      addCompanyManagement(companyId, {
        individual_id: individualId,
        relationship_type: type,
        ...(positionName.trim() === "" ? {} : { position_name: positionName.trim() }),
        ...(effectiveFrom === "" ? {} : { effective_from: effectiveFrom }),
      }),
    onSuccess: onDone,
    onError: (error: unknown) => setErrorKey(toCompanyErrorKey(error)),
  });

  const types = options.data?.relationship_types ?? MANAGEMENT_RELATIONSHIP_TYPES;

  return (
    <form
      noValidate
      className="border-border flex max-w-md flex-col gap-4 rounded-md border p-4"
      onSubmit={(event) => {
        event.preventDefault();
        setErrorKey(null);
        mutation.mutate();
      }}
    >
      {errorKey ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {t(`errors.${errorKey}`)}
        </p>
      ) : null}

      <CandidateSelect
        id="management-individual"
        candidates={options.data?.individuals ?? []}
        value={individualId}
        onChange={setIndividualId}
        loading={options.isPending}
      />

      <div className="flex flex-col gap-2">
        <Label htmlFor="management-type">{t("relationshipTypeLabel")}</Label>
        <Select
          id="management-type"
          value={type}
          onChange={(event) => setType(event.target.value as ManagementRelationshipType)}
        >
          {types.map((code) => (
            <option key={code} value={code}>
              {t(`types.${code}`)}
            </option>
          ))}
        </Select>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="management-position">{t("positionLabel")}</Label>
        <Input
          id="management-position"
          value={positionName}
          onChange={(event) => setPositionName(event.target.value)}
        />
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="management-effective-from">{t("effectiveFromLabel")}</Label>
        <Input
          id="management-effective-from"
          type="date"
          value={effectiveFrom}
          onChange={(event) => setEffectiveFrom(event.target.value)}
        />
      </div>

      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={mutation.isPending || individualId === ""}>
          {mutation.isPending ? tActions("saving") : tActions("save")}
        </Button>
        <Button type="button" variant="outline" size="sm" onClick={onCancel}>
          {tActions("cancel")}
        </Button>
      </div>
    </form>
  );
}
