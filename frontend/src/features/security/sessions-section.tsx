"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useFormatter, useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  SecurityError,
  SecuritySection,
} from "@/features/security/security-section";
import { toApiErrorKey } from "@/lib/api/errors";
import {
  getOwnSessions,
  revokeOtherSessions,
  revokeOwnSession,
  securityQueryKeys,
} from "@/services/security";

/**
 * Where you are signed in.
 *
 * The answer to "was that me?", and the button that acts on "no". Each row is
 * named by an opaque key rather than a session id — the id is a credential, and
 * the digest names the row without being usable as one (D-074).
 *
 * The current session is marked and has no revoke button. Ending it from here
 * would be a confusing way to log out, and the account menu already does that.
 */
export function SessionsSection() {
  const t = useTranslations("security");
  const format = useFormatter();
  const queryClient = useQueryClient();

  const [password, setPassword] = useState("");
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const query = useQuery({
    queryKey: securityQueryKeys.sessions,
    queryFn: getOwnSessions,
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: securityQueryKeys.sessions });

  const revokeOne = useMutation({
    mutationFn: revokeOwnSession,
    onSuccess: async () => {
      setErrorKey(null);
      await refresh();
    },
    onError: (error: unknown) => setErrorKey(toApiErrorKey(error)),
  });

  const revokeOthers = useMutation({
    mutationFn: () => revokeOtherSessions(password),
    onSuccess: async () => {
      setPassword("");
      setErrorKey(null);
      await refresh();
    },
    onError: (error: unknown) => {
      setErrorKey(toApiErrorKey(error));
      setPassword("");
    },
  });

  const sessions = query.data ?? [];
  const others = sessions.filter((session) => !session.current);

  return (
    <SecuritySection title={t("sessionsTitle")} description={t("sessionsDescription")}>
      {errorKey ? <SecurityError>{t(`errors.${errorKey}`)}</SecurityError> : null}

      {query.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("sessionsLoading")}</span>
          {[0, 1].map((row) => (
            <Skeleton key={row} className="h-14 w-full" />
          ))}
        </div>
      ) : query.isError ? (
        <SecurityError>{t(`errors.${toApiErrorKey(query.error)}`)}</SecurityError>
      ) : sessions.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("sessionsEmpty")}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {sessions.map((session) => (
            <li
              key={session.key}
              className="border-border flex flex-wrap items-center justify-between gap-3 rounded-md border px-3 py-3"
            >
              <div className="flex flex-col gap-0.5 text-sm">
                <span className="font-medium">
                  {session.device ?? t("unknownDevice")}
                  {session.current ? (
                    <span className="text-muted-foreground font-normal">
                      {" "}
                      — {t("currentSession")}
                    </span>
                  ) : null}
                </span>
                <span className="text-muted-foreground">
                  {session.ip_address ?? t("unknownAddress")}
                  {session.last_active_at
                    ? ` — ${format.dateTime(new Date(session.last_active_at), {
                        dateStyle: "medium",
                        timeStyle: "short",
                      })}`
                    : ""}
                </span>
              </div>

              {session.current ? null : (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={revokeOne.isPending}
                  onClick={() => revokeOne.mutate(session.key)}
                >
                  {t("revokeSession")}
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}

      {others.length > 0 ? (
        <div className="border-border flex max-w-md flex-col gap-3 border-t pt-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="sessions-password">{t("currentPasswordLabel")}</Label>
            <Input
              id="sessions-password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
            <p className="text-muted-foreground text-xs">{t("passwordRequiredToChange")}</p>
          </div>

          <div>
            <Button
              type="button"
              variant="outline"
              disabled={revokeOthers.isPending || password.length === 0}
              onClick={() => revokeOthers.mutate()}
            >
              {t("revokeOtherSessions")}
            </Button>
          </div>
        </div>
      ) : null}
    </SecuritySection>
  );
}
