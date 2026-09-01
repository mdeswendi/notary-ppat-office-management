"use client";

import { useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { ButtonLink } from "@/components/ui/button-link";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SecurityError, SecurityNotice } from "@/features/security/security-section";
import { toApiErrorKey } from "@/lib/api/errors";
import { getCsrfCookie } from "@/services/auth";
import { resetPassword } from "@/services/security";

/**
 * Choose a new password from an emailed reset link.
 *
 * Unauthenticated, because the whole point is that the person cannot sign in.
 * The token and address come from the query string and are submitted with the
 * new password; neither is displayed, and the token is never stored.
 *
 * **This does not sign anybody in.** On success the interface offers the login
 * page, which is where an account with two-factor meets its second factor —
 * auto-login here would turn one email into a way past it (D-072).
 */
export function ResetPasswordForm() {
  const t = useTranslations("security");
  const tAuth = useTranslations("auth");
  const tActions = useTranslations("actions");
  const searchParams = useSearchParams();

  const token = searchParams.get("token") ?? "";
  const email = searchParams.get("email") ?? "";

  const [done, setDone] = useState(false);

  const schema = z
    .object({
      password: z.string().min(8, { message: t("validation.passwordTooShort") }),
      password_confirmation: z.string().min(1, { message: t("validation.confirmationRequired") }),
    })
    .refine((values) => values.password === values.password_confirmation, {
      path: ["password_confirmation"],
      message: t("validation.confirmationMismatch"),
    });

  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { password: "", password_confirmation: "" },
  });

  const mutation = useMutation({
    mutationFn: async (values: FormValues) => {
      // State-changing and unauthenticated, so the CSRF cookie has to be primed
      // first exactly as the login flow does.
      await getCsrfCookie();

      await resetPassword({ token, email, ...values });
    },
    onSuccess: () => {
      setDone(true);
      form.reset({ password: "", password_confirmation: "" });
    },
    onError: (error: unknown) => {
      form.setError("root", { message: t(`errors.${toApiErrorKey(error)}`) });
    },
  });

  if (token === "" || email === "") {
    return (
      <div className="flex flex-col items-start gap-4">
        <SecurityError>{t("resetMissingToken")}</SecurityError>
        <ButtonLink href="/login" variant="outline">
          {tAuth("signIn")}
        </ButtonLink>
      </div>
    );
  }

  if (done) {
    return (
      <div className="flex flex-col items-start gap-4">
        <SecurityNotice>{t("resetSuccess")}</SecurityNotice>
        <p className="text-muted-foreground text-sm">{t("resetSignInAgain")}</p>
        <ButtonLink href="/login">{tAuth("signIn")}</ButtonLink>
      </div>
    );
  }

  return (
    <form
      noValidate
      className="flex flex-col gap-5"
      onSubmit={form.handleSubmit((values) => {
        form.clearErrors("root");
        mutation.mutate(values);
      })}
    >
      {form.formState.errors.root ? (
        <SecurityError>{form.formState.errors.root.message}</SecurityError>
      ) : null}

      <div className="flex flex-col gap-2">
        <Label htmlFor="reset-password">{t("newPasswordLabel")}</Label>
        <Input
          id="reset-password"
          type="password"
          autoComplete="new-password"
          autoFocus
          aria-invalid={form.formState.errors.password ? true : undefined}
          {...form.register("password")}
        />
        <p className="text-muted-foreground text-xs">{t("passwordHint")}</p>
        {form.formState.errors.password ? (
          <p className="text-destructive text-sm">{form.formState.errors.password.message}</p>
        ) : null}
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="reset-password-confirmation">{t("confirmPasswordLabel")}</Label>
        <Input
          id="reset-password-confirmation"
          type="password"
          autoComplete="new-password"
          aria-invalid={form.formState.errors.password_confirmation ? true : undefined}
          {...form.register("password_confirmation")}
        />
        {form.formState.errors.password_confirmation ? (
          <p className="text-destructive text-sm">
            {form.formState.errors.password_confirmation.message}
          </p>
        ) : null}
      </div>

      <Button type="submit" size="lg" disabled={mutation.isPending}>
        {mutation.isPending ? tActions("saving") : t("setNewPassword")}
      </Button>
    </form>
  );
}
