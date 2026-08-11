"use client";

import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

/**
 * Recovery codes, shown once.
 *
 * "Once" is literal. The backend stores only hashes, so nothing — not this page
 * on reload, not another endpoint, not an administrator — can produce these
 * again. The dialog says so rather than leaving somebody to discover it.
 *
 * Deliberately absent: any call to `localStorage`, `sessionStorage`, or a
 * clipboard-with-persistence helper. The codes exist in this component's props
 * and vanish with it. A convenience copy stored in the browser would be a set of
 * account-recovery credentials sitting in a place no password protects.
 *
 * Printing and copying are the user's own business; the interface offers a plain
 * selectable block and gets out of the way.
 */
export function RecoveryCodesDialog({
  codes,
  open,
  onClose,
}: {
  codes: string[];
  open: boolean;
  onClose: () => void;
}) {
  const t = useTranslations("security");

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? undefined : onClose())}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("recoveryCodesTitle")}</DialogTitle>
          <DialogDescription>{t("recoveryCodesDescription")}</DialogDescription>
        </DialogHeader>

        <ul className="border-border bg-muted/40 grid grid-cols-2 gap-2 rounded-md border p-3 font-mono text-sm">
          {codes.map((code) => (
            <li key={code} className="tracking-wide select-all">
              {code}
            </li>
          ))}
        </ul>

        <p className="text-muted-foreground text-xs">{t("recoveryCodesShownOnce")}</p>

        <DialogFooter>
          <Button type="button" onClick={onClose}>
            {t("recoveryCodesSaved")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
