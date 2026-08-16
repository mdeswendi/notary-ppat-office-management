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
  DuplicateAdvisoryPanel,
  DuplicateCheckNotice,
  useDuplicateAdvisory,
} from "@/features/parties/duplicate-advisory";
import {
  getIndividualIdentity,
  individualQueryKeys,
  revealIndividualNik,
  revealIndividualNpwp,
  updateIndividualIdentity,
} from "@/services/individuals";
import { checkIndividualDuplicatesForUpdate } from "@/services/party-duplicates";
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
          // Backend-computed, for this record, with Data Scope applied — the
          // same canonical capability the sensitive duplicate signal answers to.
          // Strictly narrower than the check requires, so it never offers an
          // assist the API would refuse.
          canCheckNik={identity.can_reveal_nik}
          canCheckNpwp={identity.can_reveal_npwp}
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
 *
 * **Sensitive duplicate assistance, and only where it is authorized (M2.5).**
 * Asking whether another record already carries this NIK is a disclosure about
 * that record, so it answers to `parties.identity.nik.view_full` — and NPWP to
 * its own code. `parties.identity.update` is deliberately not enough: writing a
 * value is not licence to learn that somebody else already has it. When the
 * capability is absent the field is simply **not sent to the check**, and the
 * update itself remains available exactly as before. Nothing is inferred from
 * the absence.
 *
 * **The submitted identifier never leaves component state.** The check is a
 * mutation with no query key, so no NIK reaches a cache key; the response is
 * held here and discarded on continue, cancel, save, and unmount. Nothing is
 * written to the URL, `localStorage`, or `sessionStorage`.
 */
function IdentityForm({
  individualId,
  canCheckNik,
  canCheckNpwp,
  onDone,
  onCancel,
}: {
  individualId: string;
  canCheckNik: boolean;
  canCheckNpwp: boolean;
  onDone: () => void;
  onCancel: () => void;
}) {
  const t = useTranslations("individuals");
  const tActions = useTranslations("actions");

  const [nik, setNik] = useState("");
  const [npwp, setNpwp] = useState("");
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const advisory = useDuplicateAdvisory();

  const mutation = useMutation({
    mutationFn: () =>
      updateIndividualIdentity(individualId, {
        ...(nik.trim() === "" ? {} : { nik: nik.trim() }),
        ...(npwp.trim() === "" ? {} : { npwp: npwp.trim() }),
      }),
    onSuccess: () => {
      // Cleared immediately: the submitted values have no reason to linger, and
      // neither does any candidate the check returned for them.
      setNik("");
      setNpwp("");
      advisory.reset();
      onDone();
    },
    onError: (error: unknown) => setErrorKey(toIndividualErrorKey(error)),
  });

  /**
   * The check to run, built from the fields this caller may actually ask about.
   *
   * A field the caller cannot check is omitted rather than sent and refused: the
   * backend answers 403 for the whole request if any unauthorized sensitive
   * field is present, which would take the other field's assistance down with
   * it. The subject record is excluded server-side, so a value already stored on
   * this very record does not match itself.
   */
  const duplicateCheck = () => {
    const comparison = {
      ...(canCheckNik && nik.trim() !== "" ? { nik: nik.trim() } : {}),
      ...(canCheckNpwp && npwp.trim() !== "" ? { npwp: npwp.trim() } : {}),
    };

    if (Object.keys(comparison).length === 0) {
      return null;
    }

    return () => checkIndividualDuplicatesForUpdate(individualId, comparison);
  };

  const submit = async () => {
    setErrorKey(null);

    if (!(await advisory.gate(duplicateCheck()))) {
      return;
    }

    mutation.mutate();
  };

  return (
    <form
      noValidate
      className="flex max-w-md flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
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

      <DuplicateAdvisoryPanel
        candidates={advisory.candidates}
        onReview={advisory.dismiss}
        continueDisabled={mutation.isPending}
        onContinue={() => {
          advisory.acknowledge();
          void submit();
        }}
      />

      <DuplicateCheckNotice reason={advisory.unavailable} />

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
        {/* Disabled only while a request is in flight — never because a
            candidate was found. */}
        <Button type="submit" size="sm" disabled={mutation.isPending || advisory.checking}>
          {advisory.checking
            ? t("checkingDuplicates")
            : mutation.isPending
              ? tActions("saving")
              : tActions("save")}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => {
            advisory.reset();
            onCancel();
          }}
        >
          {tActions("cancel")}
        </Button>
      </div>
    </form>
  );
}
