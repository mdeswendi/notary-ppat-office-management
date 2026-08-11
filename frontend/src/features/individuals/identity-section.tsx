"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { toIndividualErrorKey } from "@/features/individuals/individual-errors";
import {
  getIndividualIdentity,
  individualQueryKeys,
  revealIndividualNik,
  revealIndividualNpwp,
  updateIndividualIdentity,
} from "@/services/individuals";
import type { IndividualIdentity } from "@/types/individual";

/**
 * The sensitive identity surface — D-082 as the user meets it.
 *
 * Three rules govern everything here, and each is a deliberate refusal of an
 * easier design:
 *
 * **Masked values are all the page ever loads.** The identity query returns
 * masks, never raw identifiers, so a raw NIK is not sitting in the page payload
 * waiting to be un-hidden. A "Show" control that merely toggled CSS would mean
 * the value had already been sent, logged, and cached — the appearance of
 * privacy rather than privacy.
 *
 * **Reveal is a deliberate request whose result never enters the query cache.**
 * It is a mutation, and the raw value lives in component state only. Putting it
 * in a TanStack cache would outlive the component, survive navigation, and be
 * trivially inspectable.
 *
 * **NIK and NPWP are independent.** Separate permissions, separate controls,
 * separate state. Revealing one says nothing about the other, and the backend
 * enforces that regardless of what these flags say.
 *
 * Revealed values are cleared on unmount, so leaving the page genuinely discards
 * them. Nothing is written to `localStorage`, `sessionStorage`, or the URL, and
 * there is no "remember" affordance — a revealed identifier is a moment, not a
 * setting.
 */
export function IdentitySection({
  individualId,
  canUpdate,
}: {
  individualId: string;
  canUpdate: boolean;
}) {
  const t = useTranslations("individuals");
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: individualQueryKeys.identity(individualId),
    queryFn: () => getIndividualIdentity(individualId),
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
      individualId={individualId}
      identity={query.data}
      canUpdate={canUpdate}
      onSaved={() =>
        queryClient.invalidateQueries({ queryKey: individualQueryKeys.identity(individualId) })
      }
    />
  );
}

function IdentityPanel({
  individualId,
  identity,
  canUpdate,
  onSaved,
}: {
  individualId: string;
  identity: IndividualIdentity;
  canUpdate: boolean;
  onSaved: () => void;
}) {
  const t = useTranslations("individuals");

  const [editing, setEditing] = useState(false);

  return (
    <div className="flex flex-col gap-4">
      <p className="text-muted-foreground text-sm">{t("identityDescription")}</p>

      {editing ? (
        <IdentityForm
          individualId={individualId}
          onDone={() => {
            setEditing(false);
            onSaved();
          }}
          onCancel={() => setEditing(false)}
        />
      ) : (
        <>
          <dl className="flex flex-col gap-4">
            <IdentityField
              label={t("nikLabel")}
              masked={identity.nik_masked}
              present={identity.has_nik}
              canReveal={identity.can_reveal_nik}
              reveal={() => revealIndividualNik(individualId)}
            />
            <IdentityField
              label={t("npwpLabel")}
              masked={identity.npwp_masked}
              present={identity.has_npwp}
              canReveal={identity.can_reveal_npwp}
              reveal={() => revealIndividualNpwp(individualId)}
            />
          </dl>

          {identity.can_reveal_nik || identity.can_reveal_npwp ? (
            <p className="text-muted-foreground text-xs">{t("revealHint")}</p>
          ) : null}

          {canUpdate ? (
            <div>
              <Button type="button" variant="outline" size="sm" onClick={() => setEditing(true)}>
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
 * One sensitive field, with its own independent reveal.
 *
 * The revealed value is held here and nowhere else, and the effect clears it
 * when this component goes away.
 */
function IdentityField({
  label,
  masked,
  present,
  canReveal,
  reveal,
}: {
  label: string;
  masked: string | null;
  present: boolean;
  canReveal: boolean;
  reveal: () => Promise<{ value: string | null }>;
}) {
  const t = useTranslations("individuals");

  const [revealed, setRevealed] = useState<string | null>(null);
  const [errorKey, setErrorKey] = useState<string | null>(null);

  // Discard on unmount. Navigating away must genuinely drop the value rather
  // than leave it reachable in a retained component tree.
  useEffect(() => () => setRevealed(null), []);

  const mutation = useMutation({
    mutationFn: reveal,
    onSuccess: (result) => {
      setErrorKey(null);
      setRevealed(result.value);
    },
    onError: (error: unknown) => setErrorKey(toIndividualErrorKey(error)),
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
 * Editing sensitive identity.
 *
 * Blank fields, never prefilled with the current values — prefilling would
 * require reading raw identifiers into the form, which is exactly what the
 * reveal permission exists to gate. Submitting an empty field leaves it
 * unchanged rather than clearing it.
 */
function IdentityForm({
  individualId,
  onDone,
  onCancel,
}: {
  individualId: string;
  onDone: () => void;
  onCancel: () => void;
}) {
  const t = useTranslations("individuals");
  const tActions = useTranslations("actions");

  const [nik, setNik] = useState("");
  const [npwp, setNpwp] = useState("");
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () =>
      updateIndividualIdentity(individualId, {
        ...(nik.trim() === "" ? {} : { nik: nik.trim() }),
        ...(npwp.trim() === "" ? {} : { npwp: npwp.trim() }),
      }),
    onSuccess: () => {
      // Cleared immediately: the submitted values have no reason to linger.
      setNik("");
      setNpwp("");
      onDone();
    },
    onError: (error: unknown) => setErrorKey(toIndividualErrorKey(error)),
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
        <Label htmlFor="identity-nik">{t("nikLabel")}</Label>
        <Input
          id="identity-nik"
          value={nik}
          autoComplete="off"
          onChange={(event) => setNik(event.target.value)}
        />
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="identity-npwp">{t("npwpLabel")}</Label>
        <Input
          id="identity-npwp"
          value={npwp}
          autoComplete="off"
          onChange={(event) => setNpwp(event.target.value)}
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
