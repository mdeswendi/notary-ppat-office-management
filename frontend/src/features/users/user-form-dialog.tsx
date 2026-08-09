"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
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
import { rejectedFields, toUserErrorKey } from "@/features/users/user-errors";
import { cn } from "@/lib/utils";
import { createUser, getUserOffices, updateUser, userQueryKeys } from "@/services/users";
import type { ManagedUser } from "@/types/user";

type UserFormDialogProps = {
  /** Null when creating a new account. */
  user: ManagedUser | null;
  onClose: () => void;
};

/**
 * Create or edit a user account.
 *
 * One dialog for both. The only structural difference is the password: an
 * account needs an initial one to exist, and after that changing it is an
 * account-security operation with its own flow — so the field appears on create
 * and is absent on edit, matching what the backend accepts.
 *
 * The Office list comes from the API and is already scope-filtered, so an
 * office-scoped administrator is simply offered one option. That is a
 * convenience, not the control: the backend authorizes the destination Office
 * independently, and a browser choosing a foreign Office is refused.
 *
 * Absent by design: role selector, permission selector, scope selector,
 * activation toggle, preferred-locale editing, reset-password button.
 */
export function UserFormDialog({ user, onClose }: UserFormDialogProps) {
  const t = useTranslations("users");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const isEditing = user !== null;

  const offices = useQuery({
    queryKey: userQueryKeys.options,
    queryFn: getUserOffices,
    staleTime: 5 * 60 * 1000,
  });

  const schema = z
    .object({
      name: z
        .string()
        .trim()
        .min(1, { message: t("validation.nameRequired") })
        .max(255, { message: t("validation.nameTooLong") }),
      email: z
        .string()
        .trim()
        .min(1, { message: t("validation.emailRequired") })
        .email({ message: t("validation.emailInvalid") })
        .max(255, { message: t("validation.emailTooLong") }),
      phone: z
        .string()
        .trim()
        .max(50, { message: t("validation.phoneTooLong") }),
      office_id: z.string().min(1, { message: t("validation.officeRequired") }),
      password: z.string(),
      password_confirmation: z.string(),
    })
    .superRefine((values, ctx) => {
      if (isEditing) {
        return;
      }

      // Mirrors Laravel's Password::default(); the server stays authoritative.
      if (values.password.length < 8) {
        ctx.addIssue({
          code: "custom",
          path: ["password"],
          message: t("validation.passwordTooShort"),
        });
      }

      if (values.password !== values.password_confirmation) {
        ctx.addIssue({
          code: "custom",
          path: ["password_confirmation"],
          message: t("validation.passwordMismatch"),
        });
      }
    });

  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: user?.name ?? "",
      email: user?.email ?? "",
      phone: user?.phone ?? "",
      office_id: user?.office?.id ?? "",
      password: "",
      password_confirmation: "",
    },
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const shared = {
        name: values.name,
        email: values.email,
        phone: values.phone === "" ? null : values.phone,
        office_id: values.office_id,
      };

      return user
        ? updateUser(user.id, shared)
        : createUser({
            ...shared,
            password: values.password,
            password_confirmation: values.password_confirmation,
          });
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: userQueryKeys.all });
      onClose();
    },
    onError: (error: unknown) => {
      const fields = rejectedFields(error);

      if (fields.length > 0) {
        if (fields.includes("email")) {
          form.setError("email", { message: t("validation.emailTaken") });
        }

        if (fields.includes("office_id")) {
          form.setError("office_id", { message: t("validation.officeUnavailable") });
        }

        if (fields.includes("password")) {
          form.setError("password", { message: t("validation.passwordRejected") });
        }

        if (fields.some((field) => !["email", "office_id", "password"].includes(field))) {
          form.setError("root", { message: t("errors.validation") });
        }

        return;
      }

      form.setError("root", { message: t(`errors.${toUserErrorKey(error)}`) });
    },
  });

  const onSubmit = form.handleSubmit((values) => {
    form.clearErrors("root");
    mutation.mutate(values);
  });

  const fieldError = (name: keyof FormValues) => form.formState.errors[name]?.message;

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
          <DialogTitle>{isEditing ? t("editTitle") : t("createTitle")}</DialogTitle>
          <DialogDescription>
            {isEditing ? t("editDescription") : t("createDescription")}
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
            <Label htmlFor="user-name">{t("nameLabel")}</Label>
            <Input
              id="user-name"
              autoComplete="off"
              aria-invalid={fieldError("name") ? true : undefined}
              aria-describedby={fieldError("name") ? "user-name-error" : undefined}
              {...form.register("name")}
            />
            {fieldError("name") ? (
              <p id="user-name-error" className="text-destructive text-sm">
                {fieldError("name")}
              </p>
            ) : null}
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="user-email">{t("emailLabel")}</Label>
            <Input
              id="user-email"
              type="email"
              autoComplete="off"
              aria-invalid={fieldError("email") ? true : undefined}
              aria-describedby={fieldError("email") ? "user-email-error" : undefined}
              {...form.register("email")}
            />
            {fieldError("email") ? (
              <p id="user-email-error" className="text-destructive text-sm">
                {fieldError("email")}
              </p>
            ) : null}
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="user-phone">{t("phoneLabel")}</Label>
            <Input
              id="user-phone"
              autoComplete="off"
              aria-invalid={fieldError("phone") ? true : undefined}
              aria-describedby={fieldError("phone") ? "user-phone-error" : undefined}
              {...form.register("phone")}
            />
            <p className="text-muted-foreground text-xs">{t("phoneHint")}</p>
            {fieldError("phone") ? (
              <p id="user-phone-error" className="text-destructive text-sm">
                {fieldError("phone")}
              </p>
            ) : null}
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="user-office">{t("officeLabel")}</Label>
            <select
              id="user-office"
              aria-invalid={fieldError("office_id") ? true : undefined}
              aria-describedby={fieldError("office_id") ? "user-office-error" : undefined}
              disabled={offices.isPending}
              className={cn(
                "border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 h-8 rounded-lg border px-2.5 text-sm outline-none focus-visible:ring-3",
                "aria-invalid:border-destructive aria-invalid:ring-destructive/20 disabled:opacity-50",
              )}
              {...form.register("office_id")}
            >
              <option value="">
                {offices.isPending ? t("officeLoading") : t("officePlaceholder")}
              </option>
              {offices.data?.map((office) => (
                <option key={office.id} value={office.id}>
                  {office.code} — {office.name}
                </option>
              ))}
            </select>
            {offices.isError ? (
              <p className="text-destructive text-sm">{t("errors.officesUnavailable")}</p>
            ) : null}
            {fieldError("office_id") ? (
              <p id="user-office-error" className="text-destructive text-sm">
                {fieldError("office_id")}
              </p>
            ) : null}
          </div>

          {isEditing ? null : (
            <>
              <div className="flex flex-col gap-2">
                <Label htmlFor="user-password">{t("passwordLabel")}</Label>
                <Input
                  id="user-password"
                  type="password"
                  autoComplete="new-password"
                  aria-invalid={fieldError("password") ? true : undefined}
                  aria-describedby={fieldError("password") ? "user-password-error" : undefined}
                  {...form.register("password")}
                />
                {fieldError("password") ? (
                  <p id="user-password-error" className="text-destructive text-sm">
                    {fieldError("password")}
                  </p>
                ) : null}
              </div>

              <div className="flex flex-col gap-2">
                <Label htmlFor="user-password-confirmation">{t("passwordConfirmLabel")}</Label>
                <Input
                  id="user-password-confirmation"
                  type="password"
                  autoComplete="new-password"
                  aria-invalid={fieldError("password_confirmation") ? true : undefined}
                  aria-describedby={
                    fieldError("password_confirmation") ? "user-password-confirm-error" : undefined
                  }
                  {...form.register("password_confirmation")}
                />
                {fieldError("password_confirmation") ? (
                  <p id="user-password-confirm-error" className="text-destructive text-sm">
                    {fieldError("password_confirmation")}
                  </p>
                ) : null}
              </div>
            </>
          )}

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
