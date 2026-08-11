"use client";

import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useRouter } from "@/i18n/navigation";
import { toApiErrorKey, type ApiErrorKey } from "@/lib/api/errors";
import { landingLocale } from "@/lib/i18n/landing-locale";
import { authQueryKeys, getCurrentUser } from "@/services/auth";
import { submitTwoFactorChallenge } from "@/services/security";

/**
 * The second step of signing in.
 *
 * Shown only after the password was accepted for an account with two-factor
 * enabled. At this point **nothing is authenticated** — the server created no
 * session, so this form is not a gate in front of an open door; it is the door
 * (D-075).
 *
 * A recovery code is offered as an alternative rather than a separate screen.
 * Somebody reaching for one has lost their phone and is already having a bad
 * day; making them find a different page first does not help.
 *
 * No code is stored anywhere. Both fields live in component state for the life
 * of this form and are never written to `localStorage` or `sessionStorage`.
 */
export function TwoFactorChallengeForm({ onExpired }: { onExpired: () => void }) {
  const t = useTranslations("auth");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const [code, setCode] = useState("");
  const [recoveryCode, setRecoveryCode] = useState("");
  const [useRecovery, setUseRecovery] = useState(false);
  const [errorKey, setErrorKey] = useState<ApiErrorKey | null>(null);

  const mutation = useMutation({
    mutationFn: async () => {
      await submitTwoFactorChallenge(
        useRecovery ? { recovery_code: recoveryCode.trim() } : { code: code.trim() },
      );

      // The session exists only now, so this is the first moment an identity
      // can be fetched.
      return getCurrentUser();
    },
    onSuccess: (user) => {
      queryClient.setQueryData(authQueryKeys.me, user);

      router.replace("/dashboard", { locale: landingLocale(user.preferred_locale) });
      router.refresh();
    },
    onError: (error: unknown) => {
      const key = toApiErrorKey(error);

      setErrorKey(key);
      setCode("");
      setRecoveryCode("");

      // A 422 here is ambiguous — a wrong code, or a challenge that timed out.
      // The parent decides whether to send the person back to the password
      // step; this form only reports.
      if (key === "sessionExpired") {
        onExpired();
      }
    },
  });

  const isSubmitting = mutation.isPending;
  const canSubmit = useRecovery ? recoveryCode.trim().length > 0 : code.trim().length === 6;

  return (
    <form
      noValidate
      className="flex flex-col gap-5"
      onSubmit={(event) => {
        event.preventDefault();
        setErrorKey(null);
        mutation.mutate();
      }}
    >
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-medium">{t("twoFactorTitle")}</h2>
        <p className="text-muted-foreground text-sm">
          {useRecovery ? t("twoFactorRecoveryDescription") : t("twoFactorDescription")}
        </p>
      </div>

      {errorKey ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {t(`errors.${errorKey}`)}
        </p>
      ) : null}

      {useRecovery ? (
        <div className="flex flex-col gap-2">
          <Label htmlFor="recovery-code">{t("recoveryCodeLabel")}</Label>
          <Input
            id="recovery-code"
            // Never offered to a password manager and never autofilled: a
            // recovery code is single use, so a stored one is a stale one.
            autoComplete="off"
            autoFocus
            spellCheck={false}
            value={recoveryCode}
            onChange={(event) => setRecoveryCode(event.target.value)}
          />
        </div>
      ) : (
        <div className="flex flex-col gap-2">
          <Label htmlFor="two-factor-code">{t("codeLabel")}</Label>
          <Input
            id="two-factor-code"
            inputMode="numeric"
            // The one autocomplete token browsers and password managers use for
            // a one-time code, so a phone can offer it from an SMS or an
            // authenticator without the value being retained.
            autoComplete="one-time-code"
            autoFocus
            maxLength={6}
            value={code}
            onChange={(event) => setCode(event.target.value.replace(/\D/g, ""))}
          />
          <p className="text-muted-foreground text-xs">{t("codeHint")}</p>
        </div>
      )}

      <Button type="submit" size="lg" disabled={isSubmitting || !canSubmit}>
        {isSubmitting ? t("verifying") : t("verify")}
      </Button>

      <div className="flex flex-col gap-2 text-sm">
        <button
          type="button"
          className="text-muted-foreground hover:text-foreground focus-visible:ring-ring self-start rounded-md underline underline-offset-4 focus-visible:ring-2 focus-visible:outline-none"
          onClick={() => {
            setUseRecovery((current) => !current);
            setErrorKey(null);
            setCode("");
            setRecoveryCode("");
          }}
        >
          {useRecovery ? t("useAuthenticatorInstead") : t("useRecoveryCodeInstead")}
        </button>

        <button
          type="button"
          className="text-muted-foreground hover:text-foreground focus-visible:ring-ring self-start rounded-md underline underline-offset-4 focus-visible:ring-2 focus-visible:outline-none"
          onClick={onExpired}
        >
          {tActions("cancel")}
        </button>
      </div>
    </form>
  );
}
