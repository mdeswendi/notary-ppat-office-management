"use client";

import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { RecoveryCodesDialog } from "@/features/security/recovery-codes-dialog";
import {
  SecurityError,
  SecurityNotice,
  SecuritySection,
} from "@/features/security/security-section";
import { toApiErrorKey } from "@/lib/api/errors";
import {
  beginTwoFactorEnrolment,
  confirmTwoFactorEnrolment,
  disableTwoFactor,
  regenerateRecoveryCodes,
  securityQueryKeys,
} from "@/services/security";
import type { SecurityOverview, TwoFactorEnrolment } from "@/types/security";

/**
 * Two-factor authentication.
 *
 * Three states, and keeping them distinct is the point:
 *
 *   off        no secret, nothing required at login
 *   enrolling  a secret exists but login is unchanged until a code verifies
 *   on         confirmed, and the second factor is required
 *
 * The middle state is what stops a failed scan from becoming a lockout (D-076),
 * so the interface never claims two-factor is on before the backend agrees.
 *
 * The secret and the QR code live in component state only. They are not written
 * to the query cache, not stored in the browser, and are dropped when enrolment
 * finishes or is abandoned — the server will not serve them again.
 */
export function TwoFactorSection({ overview }: { overview: SecurityOverview }) {
  const t = useTranslations("security");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [enrolment, setEnrolment] = useState<TwoFactorEnrolment | null>(null);
  const [code, setCode] = useState("");
  const [password, setPassword] = useState("");
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
  const [errorKey, setErrorKey] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const refreshOverview = () =>
    queryClient.invalidateQueries({ queryKey: securityQueryKeys.overview });

  const begin = useMutation({
    mutationFn: beginTwoFactorEnrolment,
    onSuccess: (data) => {
      setEnrolment(data);
      setErrorKey(null);
      setNotice(null);
    },
    onError: (error: unknown) => setErrorKey(toApiErrorKey(error)),
  });

  const confirm = useMutation({
    mutationFn: () => confirmTwoFactorEnrolment(code.trim()),
    onSuccess: async (codes) => {
      // The secret is dropped the moment it stops being needed.
      setEnrolment(null);
      setCode("");
      setErrorKey(null);
      setRecoveryCodes(codes);

      await refreshOverview();
    },
    onError: (error: unknown) => {
      setErrorKey(toApiErrorKey(error));
      setCode("");
    },
  });

  const disable = useMutation({
    mutationFn: () => disableTwoFactor(password),
    onSuccess: async () => {
      setPassword("");
      setErrorKey(null);
      setNotice(t("twoFactorDisabled"));

      await refreshOverview();
    },
    onError: (error: unknown) => {
      setErrorKey(toApiErrorKey(error));
      setPassword("");
    },
  });

  const regenerate = useMutation({
    mutationFn: () => regenerateRecoveryCodes(password),
    onSuccess: async (codes) => {
      setPassword("");
      setErrorKey(null);
      setRecoveryCodes(codes);

      await refreshOverview();
    },
    onError: (error: unknown) => {
      setErrorKey(toApiErrorKey(error));
      setPassword("");
    },
  });

  const busy = begin.isPending || confirm.isPending || disable.isPending || regenerate.isPending;

  return (
    <SecuritySection title={t("twoFactorTitle")} description={t("twoFactorDescription")}>
      {errorKey ? <SecurityError>{t(`errors.${errorKey}`)}</SecurityError> : null}
      {notice ? <SecurityNotice>{notice}</SecurityNotice> : null}

      {/* Status is stated in words, not conveyed by colour alone (CLAUDE.md
          section 49). */}
      <p className="text-sm">
        <span className="font-medium">{t("statusLabel")}: </span>
        <span className={overview.two_factor_enabled ? "text-foreground" : "text-muted-foreground"}>
          {overview.two_factor_enabled ? t("twoFactorOn") : t("twoFactorOff")}
        </span>
      </p>

      {overview.two_factor_enabled ? (
        <EnabledControls
          overview={overview}
          password={password}
          setPassword={setPassword}
          busy={busy}
          onDisable={() => disable.mutate()}
          onRegenerate={() => regenerate.mutate()}
        />
      ) : enrolment ? (
        <div className="flex flex-col gap-4">
          <p className="text-sm">{t("scanInstruction")}</p>

          {/* Server-rendered SVG, so no QR library ships to the browser. It is
              generated from the provisioning URI on this request only. */}
          <div
            className="border-border bg-card w-fit rounded-md border p-3"
            aria-label={t("qrCodeAlt")}
            role="img"
            dangerouslySetInnerHTML={{ __html: enrolment.qr_code_svg }}
          />

          <div className="flex flex-col gap-1">
            <span className="text-sm font-medium">{t("manualEntryLabel")}</span>
            <code className="bg-muted w-fit rounded px-2 py-1 font-mono text-sm tracking-wider select-all">
              {enrolment.secret}
            </code>
            <p className="text-muted-foreground text-xs">{t("manualEntryHint")}</p>
          </div>

          <div className="flex max-w-xs flex-col gap-2">
            <Label htmlFor="enrolment-code">{t("codeLabel")}</Label>
            <Input
              id="enrolment-code"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={code}
              onChange={(event) => setCode(event.target.value.replace(/\D/g, ""))}
            />
          </div>

          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              disabled={busy || code.length !== 6}
              onClick={() => confirm.mutate()}
            >
              {confirm.isPending ? tActions("saving") : t("confirmTwoFactor")}
            </Button>

            <Button
              type="button"
              variant="outline"
              disabled={busy}
              onClick={() => {
                setEnrolment(null);
                setCode("");
                setErrorKey(null);
              }}
            >
              {tActions("cancel")}
            </Button>
          </div>

          <p className="text-muted-foreground text-xs">{t("notActiveUntilConfirmed")}</p>
        </div>
      ) : (
        <div>
          <Button type="button" disabled={busy} onClick={() => begin.mutate()}>
            {begin.isPending ? tActions("saving") : t("enableTwoFactor")}
          </Button>
        </div>
      )}

      <RecoveryCodesDialog
        codes={recoveryCodes ?? []}
        open={recoveryCodes !== null}
        onClose={() => setRecoveryCodes(null)}
      />
    </SecuritySection>
  );
}

/**
 * The controls available once two-factor is confirmed.
 *
 * Both of them weaken or replace the second factor, so both ask for the password
 * first. One field serves both, because they are never used at the same moment
 * and two identical password boxes side by side invite typing into the wrong one.
 */
function EnabledControls({
  overview,
  password,
  setPassword,
  busy,
  onDisable,
  onRegenerate,
}: {
  overview: SecurityOverview;
  password: string;
  setPassword: (value: string) => void;
  busy: boolean;
  onDisable: () => void;
  onRegenerate: () => void;
}) {
  const t = useTranslations("security");

  return (
    <div className="flex flex-col gap-4">
      <p className="text-muted-foreground text-sm">
        {t("recoveryCodesRemaining", { count: overview.recovery_codes_remaining })}
      </p>

      <div className="flex max-w-xs flex-col gap-2">
        <Label htmlFor="two-factor-password">{t("currentPasswordLabel")}</Label>
        <Input
          id="two-factor-password"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
        />
        <p className="text-muted-foreground text-xs">{t("passwordRequiredToChange")}</p>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="outline"
          disabled={busy || password.length === 0}
          onClick={onRegenerate}
        >
          {t("regenerateRecoveryCodes")}
        </Button>

        <Button
          type="button"
          variant="outline"
          disabled={busy || password.length === 0}
          onClick={onDisable}
        >
          {t("disableTwoFactor")}
        </Button>
      </div>
    </div>
  );
}
