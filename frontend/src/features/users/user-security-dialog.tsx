"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useFormatter, useTranslations } from "next-intl";

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
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { toUserErrorKey } from "@/features/users/user-errors";
import {
  disableUserTwoFactor,
  getUserSessions,
  revokeUserSessions,
  securityQueryKeys,
  sendUserPasswordReset,
} from "@/services/security";
import { userQueryKeys } from "@/services/users";
import type { ManagedUser } from "@/types/user";

/**
 * What an administrator may do to somebody else's account security.
 *
 * Three actions, and the shape of the list is the design:
 *
 *   • send a reset link — to the user's own mailbox, never to the administrator
 *   • end every session — the incident response
 *   • remove two-factor — the lost-phone recovery
 *
 * Each sits behind its own canonical permission and each is authorized again on
 * the server; the guards here only keep the interface honest about what this
 * account can do.
 *
 * Nothing in this dialog can ever *acquire* access. There is no field for
 * choosing a password, no place a token is shown, and no way to read a
 * two-factor secret — because no endpoint offers any of those. An administrator
 * who could silently become another user could sign a deed as them (D-071).
 */
export function UserSecurityDialog({ user, onClose }: { user: ManagedUser; onClose: () => void }) {
  const t = useTranslations("users.security");
  const tUsers = useTranslations("users");
  const tActions = useTranslations("actions");
  const format = useFormatter();
  const queryClient = useQueryClient();

  const [notice, setNotice] = useState<string | null>(null);
  const [errorKey, setErrorKey] = useState<string | null>(null);
  const [reason, setReason] = useState("");

  const sessions = useQuery({
    queryKey: securityQueryKeys.userSessions(user.id),
    queryFn: () => getUserSessions(user.id),
    // A 403 here is an ordinary outcome for an administrator who may manage the
    // user but not view their devices, so it is not retried into a spinner.
    retry: false,
  });

  const fail = (error: unknown) => {
    setNotice(null);
    setErrorKey(toUserErrorKey(error));
  };

  const sendReset = useMutation({
    mutationFn: () => sendUserPasswordReset(user.id),
    onSuccess: () => {
      setErrorKey(null);
      setNotice(t("resetSent"));
    },
    onError: fail,
  });

  const revoke = useMutation({
    mutationFn: () => revokeUserSessions(user.id),
    onSuccess: async () => {
      setErrorKey(null);
      setNotice(t("sessionsRevoked"));
      await queryClient.invalidateQueries({ queryKey: securityQueryKeys.userSessions(user.id) });
    },
    onError: fail,
  });

  const removeTwoFactor = useMutation({
    mutationFn: () => disableUserTwoFactor(user.id, reason.trim() || undefined),
    onSuccess: async () => {
      setErrorKey(null);
      setNotice(t("twoFactorRemoved"));
      setReason("");
      await queryClient.invalidateQueries({ queryKey: userQueryKeys.all });
    },
    onError: fail,
  });

  const busy = sendReset.isPending || revoke.isPending || removeTwoFactor.isPending;

  return (
    <Dialog open onOpenChange={(next) => (next ? undefined : onClose())}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{t("title")}</DialogTitle>
          <DialogDescription>{t("description", { name: user.name })}</DialogDescription>
        </DialogHeader>

        {errorKey ? (
          <p
            role="alert"
            className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
          >
            {tUsers(`errors.${errorKey}`)}
          </p>
        ) : null}

        {notice ? (
          <p
            role="status"
            className="border-border bg-muted/40 rounded-md border px-3 py-2 text-sm"
          >
            {notice}
          </p>
        ) : null}

        <div className="flex flex-col gap-5">
          <PermissionGuard permission="users.reset_password">
            <section className="flex flex-col gap-2">
              <h3 className="text-sm font-medium">{t("resetTitle")}</h3>
              <p className="text-muted-foreground text-sm">{t("resetDescription")}</p>
              <div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={busy}
                  onClick={() => sendReset.mutate()}
                >
                  {sendReset.isPending ? tActions("saving") : t("sendReset")}
                </Button>
              </div>
            </section>
          </PermissionGuard>

          <PermissionGuard permission="security.sessions.view">
            <section className="flex flex-col gap-2">
              <h3 className="text-sm font-medium">{t("sessionsTitle")}</h3>

              {sessions.isPending ? (
                <Skeleton className="h-10 w-full" />
              ) : sessions.isError ? (
                <p className="text-muted-foreground text-sm">{t("sessionsUnavailable")}</p>
              ) : sessions.data.length === 0 ? (
                <p className="text-muted-foreground text-sm">{t("sessionsEmpty")}</p>
              ) : (
                <ul className="flex flex-col gap-1 text-sm">
                  {sessions.data.map((session) => (
                    <li key={session.key} className="text-muted-foreground">
                      {session.device ?? t("unknownDevice")} — {session.ip_address ?? "—"}
                      {session.last_active_at
                        ? ` — ${format.dateTime(new Date(session.last_active_at), {
                            dateStyle: "short",
                            timeStyle: "short",
                          })}`
                        : ""}
                    </li>
                  ))}
                </ul>
              )}

              <PermissionGuard permission="security.sessions.revoke">
                <div>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={busy}
                    onClick={() => revoke.mutate()}
                  >
                    {revoke.isPending ? tActions("saving") : t("revokeSessions")}
                  </Button>
                </div>
              </PermissionGuard>
            </section>
          </PermissionGuard>

          <PermissionGuard permission="security.mfa.manage">
            <section className="flex flex-col gap-2">
              <h3 className="text-sm font-medium">{t("twoFactorTitle")}</h3>
              <p className="text-muted-foreground text-sm">{t("twoFactorDescription")}</p>

              <div className="flex flex-col gap-2">
                <Label htmlFor="two-factor-reason">{t("reasonLabel")}</Label>
                <Input
                  id="two-factor-reason"
                  value={reason}
                  maxLength={500}
                  onChange={(event) => setReason(event.target.value)}
                />
                <p className="text-muted-foreground text-xs">{t("reasonHint")}</p>
              </div>

              <div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={busy}
                  onClick={() => removeTwoFactor.mutate()}
                >
                  {removeTwoFactor.isPending ? tActions("saving") : t("removeTwoFactor")}
                </Button>
              </div>
            </section>
          </PermissionGuard>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            {tActions("cancel")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
