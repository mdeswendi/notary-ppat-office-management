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
import { toRoleErrorKey } from "@/features/roles/role-errors";
import { deleteRole, roleQueryKeys } from "@/services/roles";
import type { Role } from "@/types/role";

type DeleteRoleDialogProps = {
  role: Role;
  onClose: () => void;
};

/**
 * Confirm deleting a role.
 *
 * Deletion is irreversible and takes the role's permission and Data Scope
 * configuration with it, so it is never a single click.
 *
 * A role somebody still holds is refused by the backend with 409, and that
 * refusal is shown here rather than being turned into an offer to detach
 * people — removing a role must not become an unannounced decision about who
 * can do their job. The dialog stays open so the message is read.
 *
 * Mounted only while a role is selected, so the parent's unmount is what
 * clears the failure state. Nothing is reset in an effect.
 */
export function DeleteRoleDialog({ role, onClose }: DeleteRoleDialogProps) {
  const t = useTranslations("roles");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const mutation = useMutation({
    mutationFn: () => deleteRole(role.id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: roleQueryKeys.all });
      onClose();
    },
  });

  const errorKey = mutation.isError ? toRoleErrorKey(mutation.error) : null;

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
          <DialogTitle>{t("deleteTitle")}</DialogTitle>
          <DialogDescription>{t("deleteDescription", { name: role.name })}</DialogDescription>
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
            variant="destructive"
            onClick={() => mutation.mutate()}
            disabled={mutation.isPending}
          >
            {mutation.isPending ? tActions("deleting") : tActions("delete")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
