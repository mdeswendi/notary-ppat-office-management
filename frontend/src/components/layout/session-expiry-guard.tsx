"use client";

import { AxiosError } from "axios";
import { useQueryClient } from "@tanstack/react-query";
import { useRouter } from "@/i18n/navigation";
import { useCallback, useEffect, useRef } from "react";

import { getCurrentUser, logout } from "@/services/auth";

const DEFAULT_IDLE_TIMEOUT_MINUTES = 120;
const SESSION_RECHECK_COOLDOWN_MS = 30_000;

function getIdleTimeoutMs(): number {
  const configuredMinutes = Number(process.env.NEXT_PUBLIC_SESSION_IDLE_TIMEOUT_MINUTES);
  const timeoutMinutes =
    Number.isFinite(configuredMinutes) && configuredMinutes > 0
      ? configuredMinutes
      : DEFAULT_IDLE_TIMEOUT_MINUTES;

  return timeoutMinutes * 60_000;
}

function isExpiredSession(error: unknown): boolean {
  return error instanceof AxiosError && [401, 419].includes(error.response?.status ?? 0);
}

/**
 * Ends an unattended browser session and verifies server-side auth whenever
 * the user returns to an open tab. Laravel remains authoritative: a 401/419
 * clears the client cache and sends the user back to the localized login page.
 */
export function SessionExpiryGuard() {
  const queryClient = useQueryClient();
  const router = useRouter();
  const lastActivityAt = useRef(0);
  const lastSessionCheckAt = useRef(0);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const sessionCheck = useRef<Promise<void> | null>(null);
  const expired = useRef(false);

  const endSession = useCallback(() => {
    if (expired.current) return;
    expired.current = true;

    if (timer.current) clearTimeout(timer.current);
    queryClient.clear();
    router.replace("/login");
    router.refresh();

    // Best-effort server logout. The local redirect must not wait on the
    // network, and Laravel may already have expired the session.
    void logout().catch(() => undefined);
  }, [queryClient, router]);

  useEffect(() => {
    const idleTimeoutMs = getIdleTimeoutMs();
    lastActivityAt.current = Date.now();

    const scheduleTimeout = () => {
      if (timer.current) clearTimeout(timer.current);
      const remainingMs = idleTimeoutMs - (Date.now() - lastActivityAt.current);
      timer.current = setTimeout(endSession, Math.max(0, remainingMs));
    };

    const verifySession = () => {
      const now = Date.now();
      if (expired.current || now - lastSessionCheckAt.current < SESSION_RECHECK_COOLDOWN_MS) {
        return;
      }
      if (sessionCheck.current) return;

      lastSessionCheckAt.current = now;
      sessionCheck.current = getCurrentUser()
        .then(() => undefined)
        .catch((error: unknown) => {
          if (isExpiredSession(error)) endSession();
        })
        .finally(() => {
          sessionCheck.current = null;
        });
    };

    const handleResume = () => {
      if (document.visibilityState === "hidden") return;

      if (Date.now() - lastActivityAt.current >= idleTimeoutMs) {
        endSession();
        return;
      }

      // Returning to the app is activity, but the server still decides whether
      // the cookie session is valid (production may use a shorter lifetime).
      lastActivityAt.current = Date.now();
      scheduleTimeout();
      verifySession();
    };

    const handleActivity = () => {
      if (document.visibilityState === "hidden" || expired.current) return;

      const now = Date.now();
      if (now - lastActivityAt.current >= idleTimeoutMs) {
        endSession();
        return;
      }

      lastActivityAt.current = now;
      scheduleTimeout();
    };

    scheduleTimeout();
    window.addEventListener("focus", handleResume);
    window.addEventListener("pageshow", handleResume);
    document.addEventListener("visibilitychange", handleResume);
    window.addEventListener("pointerdown", handleActivity, { passive: true });
    window.addEventListener("keydown", handleActivity);
    window.addEventListener("scroll", handleActivity, { passive: true });

    return () => {
      if (timer.current) clearTimeout(timer.current);
      window.removeEventListener("focus", handleResume);
      window.removeEventListener("pageshow", handleResume);
      document.removeEventListener("visibilitychange", handleResume);
      window.removeEventListener("pointerdown", handleActivity);
      window.removeEventListener("keydown", handleActivity);
      window.removeEventListener("scroll", handleActivity);
    };
  }, [endSession]);

  return null;
}
