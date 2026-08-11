"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
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
import { toUserErrorKey } from "@/features/users/user-errors";
import { setUserActivation, userQueryKeys } from "@/services/users";
import type { ManagedUser } from "@/types/user";

type UserActivationDialogProps = {
  user: ManagedUser;
  /** True to enable the account, false to disable it. */
  activate: boolean;
  onClose: () => void;
};

/**
 * Confirm turning an account off, or on.
 *
 * Disabling ends somebody's ability to sign in, so it is never a single click.
 * Enabling is confirmed too, for symmetry and because restoring access is just
 * as consequential in the other direction.
 *
 * An administrator disabling their own account is refused by the backend with
 * 409, and that refusal is shown here rather than being worked around. The
 * dialog stays open so the message is read.
 *
 * Mounted only while open, so the failure state clears on unmount rather than
 * being reset in an effect.
 */
export function UserActivationDialog({ user, activate, onClose }: UserActivationDialogProps) {
  const t = useTranslations("users");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const mutation = useMutation({
    mutationFn: () => setUserActivation(user.id, activate),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: userQueryKeys.all });
      onClose();
    },
  });

  const errorKey = mutation.isError ? toUserErrorKey(mutation.error) : null;

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open) {
          onClose();
        }
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{activate ? t("enableTitle") : t("disableTitle")}</DialogTitle>
          <DialogDescription>
            {activate
              ? t("enableDescription", { name: user.name })
              : t("disableDescription", { name: user.name })}
          </DialogDescription>
        </DialogHeader>

        {errorKey ? (
          <p
            role="alert"
            className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
          >
            {t(`errors.${errorKey}`)}
          </p>
        ) : null}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose} disabled={mutation.isPending}>
            {tActions("cancel")}
          </Button>
          <Button
            type="button"
            variant={activate ? "default" : "destructive"}
            onClick={() => mutation.mutate()}
            disabled={mutation.isPending}
          >
            {mutation.isPending
              ? tActions("saving")
              : activate
                ? t("enableAction")
                : t("disableAction")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
