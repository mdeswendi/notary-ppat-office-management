"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { toCompanyErrorKey } from "@/features/companies/company-errors";
import {
  companyQueryKeys,
  getCompanyIdentity,
  revealCompanyTaxId,
  updateCompanyIdentity,
} from "@/services/companies";
import type { CompanyIdentity } from "@/types/company";

/**
 * The Company sensitive tax identity section — D-082 in the interface.
 *
 * **The masked value is all the browser ever receives by default.** Masking is
 * computed server-side; this component does not hide a raw value it already
 * holds, because it never holds one. If it did, the value would already have
 * been sent, logged, and cached — the appearance of privacy rather than privacy.
 *
 * **Reveal is a deliberate request whose result never enters the query cache.**
 * It is a mutation, and the raw value lives in component state only. Putting it
 * in a TanStack cache would outlive the component, survive navigation, and be
 * trivially inspectable.
 *
 * The revealed value is cleared on unmount, so leaving the page genuinely
 * discards it. Nothing is written to `localStorage`, `sessionStorage`, or the
 * URL, and there is no "remember" affordance — a revealed identifier is a
 * moment, not a setting. Saving a new value does not reveal it either: the
 * update answers with a mask, and the interface shows exactly that.
 */
export function CompanyIdentitySection({
  companyId,
  canUpdate,
}: {
  companyId: string;
  canUpdate: boolean;
}) {
  const t = useTranslations("companies");
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: companyQueryKeys.identity(companyId),
    queryFn: () => getCompanyIdentity(companyId),
    // A 403 here is an ordinary outcome for somebody who may view the record but
    // not its identity, so it is not retried into a spinner.
    retry: false,
  });

  if (query.isPending) {
    return <Skeleton className="h-32 w-full" />;
  }

  // Not an error state: lacking identity permission is a normal condition, and
  // presenting it as a failure would suggest something went wrong.
  if (query.isError) {
    return <p className="text-muted-foreground text-sm">{t("identityLocked")}</p>;
  }

  return (
    <IdentityPanel
      companyId={companyId}
      identity={query.data}
      canUpdate={canUpdate}
      onSaved={() =>
        queryClient.invalidateQueries({ queryKey: companyQueryKeys.identity(companyId) })
      }
    />
  );
}

function IdentityPanel({
  companyId,
  identity,
  canUpdate,
  onSaved,
}: {
  companyId: string;
  identity: CompanyIdentity;
  canUpdate: boolean;
  onSaved: () => void;
}) {
  const t = useTranslations("companies");

  const [editing, setEditing] = useState(false);

  return (
    <div className="flex flex-col gap-4">
      <p className="text-muted-foreground text-sm">{t("identityDescription")}</p>

      {editing ? (
        <IdentityForm
          companyId={companyId}
          onDone={() => {
            setEditing(false);
            onSaved();
          }}
          onCancel={() => setEditing(false)}
        />
      ) : (
        <>
          <dl className="flex flex-col gap-4">
            <TaxIdField
              label={t("taxIdLabel")}
              masked={identity.tax_id_masked}
              present={identity.has_tax_id}
              canReveal={identity.can_reveal_tax_id}
              companyId={companyId}
            />
          </dl>

          {identity.can_reveal_tax_id ? (
            <p className="text-muted-foreground text-xs">{t("revealHint")}</p>
          ) : null}

          {canUpdate ? (
            <div>
              <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                {t("editIdentity")}
              </Button>
            </div>
          ) : null}
        </>
      )}
    </div>
  );
}

/**
 * The tax identifier, with its explicit reveal.
 *
 * The revealed value is held here and nowhere else, and the effect clears it on
 * unmount.
 */
function TaxIdField({
  label,
  masked,
  present,
  canReveal,
  companyId,
}: {
  label: string;
  masked: string | null;
  present: boolean;
  canReveal: boolean;
  companyId: string;
}) {
  const t = useTranslations("companies");

  const [revealed, setRevealed] = useState<string | null>(null);
  const [errorKey, setErrorKey] = useState<string | null>(null);

  // Discard on unmount. Navigating away must genuinely drop the value rather
  // than leave it reachable in a retained component tree.
  useEffect(() => () => setRevealed(null), []);

  const mutation = useMutation({
    mutationFn: () => revealCompanyTaxId(companyId),
    onSuccess: (result) => {
      setErrorKey(null);
      setRevealed(result.value);
    },
    onError: (error: unknown) => setErrorKey(toCompanyErrorKey(error)),
  });

  return (
    <div className="flex flex-col gap-1">
      <dt className="text-sm font-medium">{label}</dt>
      <dd className="flex flex-wrap items-center gap-3">
        {present ? (
          <span className="font-mono text-sm tracking-wide">{revealed ?? masked}</span>
        ) : (
          <span className="text-muted-foreground text-sm">{t("notRecorded")}</span>
        )}

        {present && canReveal ? (
          revealed === null ? (
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={mutation.isPending}
              onClick={() => mutation.mutate()}
            >
              {mutation.isPending ? t("revealing") : t("reveal")}
            </Button>
          ) : (
            <Button type="button" variant="ghost" size="sm" onClick={() => setRevealed(null)}>
              {t("hide")}
            </Button>
          )
        ) : null}
      </dd>

      {errorKey ? (
        <p role="alert" className="text-destructive text-sm">
          {t(`errors.${errorKey}`)}
        </p>
      ) : null}
    </div>
  );
}

/**
 * Editing the sensitive tax identity.
 *
 * Blank field, never prefilled with the current value — prefilling would require
 * reading the raw identifier into the form, which is exactly what the reveal
 * permission exists to gate. Submitting an empty field leaves it unchanged
 * rather than clearing it.
 */
function IdentityForm({
  companyId,
  onDone,
  onCancel,
}: {
  companyId: string;
  onDone: () => void;
  onCancel: () => void;
}) {
  const t = useTranslations("companies");
  const tActions = useTranslations("actions");

  const [taxId, setTaxId] = useState("");
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () =>
      updateCompanyIdentity(companyId, {
        ...(taxId.trim() === "" ? {} : { tax_id: taxId.trim() }),
      }),
    onSuccess: () => {
      // Cleared immediately: the submitted value has no reason to linger.
      setTaxId("");
      onDone();
    },
    onError: (error: unknown) => setErrorKey(toCompanyErrorKey(error)),
  });

  return (
    <form
      noValidate
      className="flex max-w-md flex-col gap-4"
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

      <div className="flex flex-col gap-2">
        <Label htmlFor="identity-tax-id">{t("taxIdLabel")}</Label>
        <Input
          id="identity-tax-id"
          value={taxId}
          autoComplete="off"
          onChange={(event) => setTaxId(event.target.value)}
        />
      </div>

      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={mutation.isPending}>
          {mutation.isPending ? tActions("saving") : tActions("save")}
        </Button>
        <Button type="button" variant="outline" size="sm" onClick={onCancel}>
          {tActions("cancel")}
        </Button>
      </div>
    </form>
  );
}
