"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { toCompanyErrorKey } from "@/features/companies/company-errors";
import {
  CandidateSelect,
  EndRelationshipDialog,
  RelationshipPeriod,
  RelationshipPerson,
} from "@/features/companies/relationship-shared";
import {
  addCompanyShareholder,
  companyShareholderKeys,
  endCompanyShareholder,
  getCompanyShareholderOptions,
  getCompanyShareholders,
} from "@/services/company-relationships";
import {
  OWNERSHIP_RELATIONSHIP_TYPES,
  type OwnershipRelationshipType,
} from "@/types/company-relationship";

/**
 * Who owns the Company — shareholders and beneficial owners.
 *
 * **Its own query, deliberately.** Rendered only for a holder of
 * `companies.shareholders.view` and fetching its own endpoint, so a holder of
 * `companies.management.view` never causes ownership data to be requested. That
 * is the whole point of the permission split: ownership is not visible merely
 * because somebody may see who runs the organization (D-083).
 *
 * **Nothing is derived from the percentages.** No total, no remainder, no
 * "ownership complete", no majority controller, and no beneficial owner
 * inferred from a large holding. Each figure is shown exactly as recorded, and
 * an unrecorded one shows as unrecorded rather than as zero — those are
 * different facts.
 *
 * No `position_name` appears here. That belongs to the management surface.
 */
export function CompanyShareholdersSection({
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
    queryKey: companyShareholderKeys.list(companyId),
    queryFn: () => getCompanyShareholders(companyId),
    retry: false,
  });

  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: companyShareholderKeys.all(companyId) });

  if (query.isPending) {
    return <Skeleton className="h-32 w-full" />;
  }

  if (query.isError) {
    return (
      <BaseErrorState
        title={t("shareholdersErrorTitle")}
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
      <p className="text-muted-foreground text-sm">{t("shareholdersDescription")}</p>

      {relationships.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("shareholdersEmpty")}</p>
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
                  {relationship.ownership_percentage !== null
                    ? ` · ${relationship.ownership_percentage}%`
                    : ""}
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
          <AddShareholderForm
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
              {t("addShareholderAction")}
            </Button>
          </div>
        )
      ) : null}

      <EndRelationshipDialog
        open={endingId !== null}
        onOpenChange={(open) => (open ? undefined : setEndingId(null))}
        onEnd={(effectiveUntil) =>
          endCompanyShareholder(companyId, endingId ?? "", { effective_until: effectiveUntil })
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
 * Recording a new ownership relationship.
 *
 * The type choices are the two ownership codes and nothing else. The percentage
 * is optional and validated only for shape — leaving it blank records no
 * holding rather than a zero one.
 */
function AddShareholderForm({
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
  const [type, setType] = useState<OwnershipRelationshipType>("SHAREHOLDER");
  const [percentage, setPercentage] = useState("");
  const [effectiveFrom, setEffectiveFrom] = useState("");
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const options = useQuery({
    queryKey: companyShareholderKeys.options(companyId),
    queryFn: () => getCompanyShareholderOptions(companyId),
    retry: false,
  });

  const mutation = useMutation({
    mutationFn: () =>
      addCompanyShareholder(companyId, {
        individual_id: individualId,
        relationship_type: type,
        ...(percentage.trim() === "" ? {} : { ownership_percentage: percentage.trim() }),
        ...(effectiveFrom === "" ? {} : { effective_from: effectiveFrom }),
      }),
    onSuccess: onDone,
    onError: (error: unknown) => setErrorKey(toCompanyErrorKey(error)),
  });

  const types = options.data?.relationship_types ?? OWNERSHIP_RELATIONSHIP_TYPES;

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
        id="shareholder-individual"
        candidates={options.data?.individuals ?? []}
        value={individualId}
        onChange={setIndividualId}
        loading={options.isPending}
      />

      <div className="flex flex-col gap-2">
        <Label htmlFor="shareholder-type">{t("relationshipTypeLabel")}</Label>
        <select
          id="shareholder-type"
          className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
          value={type}
          onChange={(event) => setType(event.target.value as OwnershipRelationshipType)}
        >
          {types.map((code) => (
            <option key={code} value={code}>
              {t(`types.${code}`)}
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="shareholder-percentage">{t("ownershipPercentageLabel")}</Label>
        <Input
          id="shareholder-percentage"
          inputMode="decimal"
          value={percentage}
          onChange={(event) => setPercentage(event.target.value)}
        />
        <p className="text-muted-foreground text-sm">{t("ownershipPercentageHint")}</p>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="shareholder-effective-from">{t("effectiveFromLabel")}</Label>
        <Input
          id="shareholder-effective-from"
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
