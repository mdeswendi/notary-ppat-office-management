"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useForm } from "react-hook-form";
import { z } from "zod";

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
import { isNameRejected, toRoleErrorKey } from "@/features/roles/role-errors";
import { createRole, renameRole, roleQueryKeys } from "@/services/roles";
import type { Role } from "@/types/role";

type RoleFormDialogProps = {
  /** Null when creating a new role. */
  role: Role | null;
  onClose: () => void;
};

/**
 * Create or rename a role.
 *
 * One dialog for both, because the two forms differ only in their title and
 * which request they send — a second component would be the same fields twice.
 *
 * The client schema mirrors the backend's technical rules and nothing more. No
 * naming convention is imposed: the nine documented default roles happen to be
 * upper snake case, but that is a starting configuration rather than a rule, and
 * an office is free to name a role "Notaris Pengganti". Uniqueness is left to
 * the server, which is the only place that can answer it.
 *
 * Mounted only while the dialog is open, so the form starts from the right
 * defaults on every open without resetting anything in an effect.
 */
export function RoleFormDialog({ role, onClose }: RoleFormDialogProps) {
  const t = useTranslations("roles");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const schema = z.object({
    name: z
      .string()
      .trim()
      .min(1, { message: t("validation.nameRequired") })
      .max(255, { message: t("validation.nameTooLong") }),
  });

  const form = useForm<z.infer<typeof schema>>({
    resolver: zodResolver(schema),
    defaultValues: { name: role?.name ?? "" },
  });

  const mutation = useMutation({
    mutationFn: (values: z.infer<typeof schema>) =>
      role ? renameRole(role.id, values.name) : createRole(values.name),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: roleQueryKeys.all });
      onClose();
    },
    onError: (error: unknown) => {
      // Only the server can judge uniqueness, so a field-level rejection comes
      // back here rather than being guessed at before submitting.
      if (isNameRejected(error)) {
        form.setError("name", { message: t("validation.nameTaken") });

        return;
      }

      form.setError("root", { message: t(`errors.${toRoleErrorKey(error)}`) });
    },
  });

  const onSubmit = form.handleSubmit((values) => {
    form.clearErrors("root");
    mutation.mutate(values);
  });

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
          <DialogTitle>{role ? t("editTitle") : t("createTitle")}</DialogTitle>
          <DialogDescription>
            {role ? t("editDescription") : t("createDescription")}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
          {form.formState.errors.root ? (
            <p
              role="alert"
              className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
            >
              {form.formState.errors.root.message}
            </p>
          ) : null}

          <div className="flex flex-col gap-2">
            <Label htmlFor="role-name">{t("nameLabel")}</Label>
            <Input
              id="role-name"
              autoComplete="off"
              aria-invalid={form.formState.errors.name ? true : undefined}
              aria-describedby={form.formState.errors.name ? "role-name-error" : undefined}
              {...form.register("name")}
            />
            {form.formState.errors.name ? (
              <p id="role-name-error" className="text-destructive text-sm">
                {form.formState.errors.name.message}
              </p>
            ) : null}
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={mutation.isPending}>
              {tActions("cancel")}
            </Button>
            <Button type="submit" disabled={mutation.isPending}>
              {mutation.isPending ? tActions("saving") : tActions("save")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
