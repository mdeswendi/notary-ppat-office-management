"use client";

import { useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  SecurityError,
  SecurityNotice,
  SecuritySection,
} from "@/features/security/security-section";
import { toApiErrorKey } from "@/lib/api/errors";
import { changePassword, securityQueryKeys } from "@/services/security";

/**
 * Change your own password.
 *
 * The current password is required, and the backend Form Request is what
 * actually enforces that — this asks for it because the interface should not
 * present a form the server will reject, not because asking here proves
 * anything.
 *
 * Zod checks length and confirmation only. The real policy, including the
 * known-compromised check, lives in `PasswordRules` on the backend; duplicating
 * it here would create a second rule to keep in step (CLAUDE.md section 44).
 *
 * Every field is cleared on success, and none of them is ever persisted
 * anywhere.
 */
export function PasswordSection() {
  const t = useTranslations("security");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [saved, setSaved] = useState(false);

  const schema = z
    .object({
      current_password: z.string().min(1, { message: t("validation.currentPasswordRequired") }),
      password: z.string().min(8, { message: t("validation.passwordTooShort") }),
      password_confirmation: z.string().min(1, { message: t("validation.confirmationRequired") }),
    })
    .refine((values) => values.password === values.password_confirmation, {
      path: ["password_confirmation"],
      message: t("validation.confirmationMismatch"),
    })
    .refine((values) => values.password !== values.current_password, {
      path: ["password"],
      message: t("validation.passwordUnchanged"),
    });

  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { current_password: "", password: "", password_confirmation: "" },
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) => changePassword(values),
    onSuccess: async () => {
      setSaved(true);
      form.reset({ current_password: "", password: "", password_confirmation: "" });

      // Other sessions were revoked server-side, so the device list on this page
      // is now wrong.
      await queryClient.invalidateQueries({ queryKey: securityQueryKeys.sessions });
    },
    onError: (error: unknown) => {
      form.setError("root", { message: t(`errors.${toApiErrorKey(error)}`) });
      form.resetField("current_password");
    },
  });

  const onSubmit = form.handleSubmit((values) => {
    setSaved(false);
    form.clearErrors("root");
    mutation.mutate(values);
  });

  return (
    <SecuritySection title={t("passwordTitle")} description={t("passwordDescription")}>
      <form onSubmit={onSubmit} noValidate className="flex max-w-md flex-col gap-4">
        {form.formState.errors.root ? (
          <SecurityError>{form.formState.errors.root.message}</SecurityError>
        ) : null}

        {saved ? <SecurityNotice>{t("passwordChanged")}</SecurityNotice> : null}

        <PasswordField
          id="current-password"
          label={t("currentPasswordLabel")}
          autoComplete="current-password"
          error={form.formState.errors.current_password?.message}
          register={form.register("current_password")}
        />

        <PasswordField
          id="new-password"
          label={t("newPasswordLabel")}
          autoComplete="new-password"
          hint={t("passwordHint")}
          error={form.formState.errors.password?.message}
          register={form.register("password")}
        />

        <PasswordField
          id="confirm-password"
          label={t("confirmPasswordLabel")}
          autoComplete="new-password"
          error={form.formState.errors.password_confirmation?.message}
          register={form.register("password_confirmation")}
        />

        <p className="text-muted-foreground text-xs">{t("passwordRevokesSessions")}</p>

        <div>
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? tActions("saving") : t("changePassword")}
          </Button>
        </div>
      </form>
    </SecuritySection>
  );
}

function PasswordField({
  id,
  label,
  autoComplete,
  hint,
  error,
  register,
}: {
  id: string;
  label: string;
  autoComplete: "current-password" | "new-password";
  hint?: string;
  error?: string;
  register: ReturnType<ReturnType<typeof useForm>["register"]>;
}) {
  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type="password"
        autoComplete={autoComplete}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${id}-error` : undefined}
        {...register}
      />
      {hint ? <p className="text-muted-foreground text-xs">{hint}</p> : null}
      {error ? (
        <p id={`${id}-error`} className="text-destructive text-sm">
          {error}
        </p>
      ) : null}
    </div>
  );
}
