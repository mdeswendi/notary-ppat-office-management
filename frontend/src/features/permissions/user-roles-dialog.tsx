"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { toPermissionErrorKey } from "@/features/permissions/permission-errors";
import { getUserRoles, permissionQueryKeys, saveUserRoles } from "@/services/permissions";
import { getRoles, roleQueryKeys } from "@/services/roles";
import type { ManagedUser } from "@/types/user";

type UserRolesDialogProps = {
  user: ManagedUser;
  onClose: () => void;
};

/**
 * Which roles a person holds.
 *
 * Membership only. What each role *can do* is configured in the Permission
 * Matrix, and there is deliberately no direct-permission control, no per-user
 * Data Scope, and no override editing here — a per-user exception is a different
 * mechanism with different consequences, and it has no administrative surface
 * yet (O-029).
 *
 * Guarded by `permissions.assign`, not by the user-management capability:
 * granting a role changes what somebody can do. Someone who may edit a
 * colleague's phone number sees a forbidden state here, which is the intended
 * answer rather than a bug.
 *
 * A save that would remove the last person able to administer authorization is
 * refused by the backend with 409 and reported as such.
 */
export function UserRolesDialog({ user, onClose }: UserRolesDialogProps) {
  const t = useTranslations("permissions");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [selected, setSelected] = useState<number[] | null>(null);

  const roles = useQuery({
    queryKey: roleQueryKeys.all,
    queryFn: getRoles,
    retry: (count, error) => toPermissionErrorKey(error) === "server" && count < 2,
  });

  const membership = useQuery({
    queryKey: permissionQueryKeys.userRoles(user.id),
    queryFn: () => getUserRoles(user.id),
    retry: (count, error) => toPermissionErrorKey(error) === "server" && count < 2,
  });

  const persisted = membership.data?.roles.map((role) => role.id) ?? [];
  const working = selected ?? persisted;

  const mutation = useMutation({
    mutationFn: () => saveUserRoles(user.id, working),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: permissionQueryKeys.userRoles(user.id) });
      onClose();
    },
  });

  const toggle = (roleId: number, checked: boolean) => {
    setSelected(checked ? [...working, roleId] : working.filter((id) => id !== roleId));
  };

  const loading = roles.isPending || membership.isPending;
  const failed = roles.isError || membership.isError;
  const errorKey = failed
    ? toPermissionErrorKey(roles.error ?? membership.error)
    : mutation.isError
      ? toPermissionErrorKey(mutation.error)
      : null;

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open) {
          onClose();
        }
      }}
    >
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{t("userRolesTitle")}</DialogTitle>
          <DialogDescription>{t("userRolesDescription", { name: user.name })}</DialogDescription>
        </DialogHeader>

        {errorKey ? (
          <p
            role="alert"
            className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
          >
            {t(`errors.${errorKey}`)}
          </p>
        ) : null}

        {loading ? (
          <div className="flex flex-col gap-2" aria-busy="true">
            <span className="sr-only">{t("loading")}</span>
            {[0, 1, 2].map((row) => (
              <Skeleton key={row} className="h-8 w-full" />
            ))}
          </div>
        ) : failed ? null : roles.data.length === 0 ? (
          <p className="text-muted-foreground text-sm">{t("noRoles")}</p>
        ) : (
          <ul className="border-border max-h-80 overflow-y-auto rounded-lg border">
            {roles.data.map((role) => {
              const inputId = `user-role-${role.id}`;

              return (
                <li
                  key={role.id}
                  className="border-border flex items-center gap-2.5 border-b px-3 py-2.5 last:border-b-0"
                >
                  <Checkbox
                    id={inputId}
                    checked={working.includes(role.id)}
                    onCheckedChange={(checked) => toggle(role.id, checked === true)}
                  />
                  <Label htmlFor={inputId} className="cursor-pointer font-normal">
                    {role.name}
                  </Label>
                </li>
              );
            })}
          </ul>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose} disabled={mutation.isPending}>
            {tActions("cancel")}
          </Button>
          <Button
            type="button"
            onClick={() => mutation.mutate()}
            disabled={mutation.isPending || loading || failed}
          >
            {mutation.isPending ? tActions("saving") : tActions("save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
