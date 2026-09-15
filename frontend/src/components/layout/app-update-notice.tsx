"use client";

import { RefreshCw } from "lucide-react";
import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";

const DEFAULT_CHECK_INTERVAL_MS = 5 * 60 * 1_000;

type AppUpdateNoticeProps = {
  currentVersion: string;
  checkIntervalMs?: number;
  onReload?: () => void;
};

type VersionResponse = {
  version?: unknown;
};

/**
 * Detects a newer Vercel deployment without interrupting work in progress.
 *
 * The browser bundle knows the deployment that produced it. This component
 * compares that value with a no-store endpoint on the current production alias
 * when the app regains focus, becomes visible, and every few minutes. A user
 * chooses when to reload so an unfinished form is never discarded silently.
 */
export function AppUpdateNotice({
  currentVersion,
  checkIntervalMs = DEFAULT_CHECK_INTERVAL_MS,
  onReload = () => window.location.reload(),
}: AppUpdateNoticeProps) {
  const t = useTranslations("appUpdate");
  const [updateAvailable, setUpdateAvailable] = useState(false);

  useEffect(() => {
    if (currentVersion === "development") {
      return;
    }

    let disposed = false;

    const checkForUpdate = async () => {
      try {
        const response = await fetch("/api/app-version", {
          cache: "no-store",
          headers: { Accept: "application/json" },
        });

        if (!response.ok) {
          return;
        }

        const payload = (await response.json()) as VersionResponse;

        if (
          !disposed &&
          typeof payload.version === "string" &&
          payload.version !== currentVersion
        ) {
          setUpdateAvailable(true);
        }
      } catch {
        // A failed background check must not disturb the user's current work.
      }
    };

    const checkWhenVisible = () => {
      if (document.visibilityState === "visible") {
        void checkForUpdate();
      }
    };

    void checkForUpdate();
    const interval = window.setInterval(() => void checkForUpdate(), checkIntervalMs);
    window.addEventListener("focus", checkForUpdate);
    document.addEventListener("visibilitychange", checkWhenVisible);

    return () => {
      disposed = true;
      window.clearInterval(interval);
      window.removeEventListener("focus", checkForUpdate);
      document.removeEventListener("visibilitychange", checkWhenVisible);
    };
  }, [checkIntervalMs, currentVersion]);

  if (!updateAvailable) {
    return null;
  }

  return (
    <aside
      role="status"
      aria-live="polite"
      className="border-border bg-card fixed right-4 bottom-4 z-50 flex max-w-sm items-start gap-3 rounded-lg border p-4 shadow-xl"
    >
      <RefreshCw className="text-primary mt-0.5 size-5 shrink-0" aria-hidden="true" />
      <div className="min-w-0 flex-1">
        <p className="font-medium">{t("title")}</p>
        <p className="text-muted-foreground mt-1 text-sm">{t("description")}</p>
        <Button type="button" size="sm" className="mt-3 gap-2" onClick={onReload}>
          <RefreshCw aria-hidden="true" />
          {t("reload")}
        </Button>
      </div>
    </aside>
  );
}
