"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { Eye, EyeOff } from "lucide-react";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useRouter } from "@/i18n/navigation";
import { toApiErrorKey, type ApiErrorKey } from "@/lib/api/errors";
import { authQueryKeys, getCurrentUser, login } from "@/services/auth";

export function LoginForm() {
  const t = useTranslations("auth");
  const tValidation = useTranslations("validation");
  const router = useRouter();
  const queryClient = useQueryClient();

  const [showPassword, setShowPassword] = useState(false);
  const [errorKey, setErrorKey] = useState<ApiErrorKey | null>(null);

  // Built here so validation messages resolve in the active locale. The
  // backend Form Request stays authoritative; this only improves feedback.
  const schema = z.object({
    email: z
      .string()
      .min(1, { message: tValidation("emailRequired") })
      .email({ message: tValidation("emailInvalid") }),
    password: z.string().min(1, { message: tValidation("passwordRequired") }),
    remember: z.boolean(),
  });

  const form = useForm<z.infer<typeof schema>>({
    resolver: zodResolver(schema),
    defaultValues: { email: "", password: "", remember: false },
  });

  const mutation = useMutation({
    mutationFn: async (values: z.infer<typeof schema>) => {
      await login(values);

      // Documented flow: the session is established, then the identity comes
      // from /api/v1/me and seeds the query cache.
      return getCurrentUser();
    },
    onSuccess: (user) => {
      queryClient.setQueryData(authQueryKeys.me, user);

      // Locale-aware: resolves to /id/dashboard or /en/dashboard. The user's
      // stored preference is not applied here — the URL stays authoritative
      // for the active locale, per D-020.
      router.replace("/dashboard");
      router.refresh();
    },
    onError: (error: unknown) => {
      setErrorKey(toApiErrorKey(error));
      form.resetField("password");
    },
  });

  const onSubmit = form.handleSubmit((values) => {
    setErrorKey(null);
    mutation.mutate(values);
  });

  const isSubmitting = mutation.isPending || form.formState.isSubmitting;

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5">
      {errorKey ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {t(`errors.${errorKey}`)}
        </p>
      ) : null}

      <div className="flex flex-col gap-2">
        <Label htmlFor="email">{t("email")}</Label>
        <Input
          id="email"
          type="email"
          autoComplete="username"
          aria-invalid={form.formState.errors.email ? true : undefined}
          aria-describedby={form.formState.errors.email ? "email-error" : undefined}
          {...form.register("email")}
        />
        {form.formState.errors.email ? (
          <p id="email-error" className="text-destructive text-sm">
            {form.formState.errors.email.message}
          </p>
        ) : null}
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="password">{t("password")}</Label>
        <div className="relative">
          <Input
            id="password"
            type={showPassword ? "text" : "password"}
            autoComplete="current-password"
            className="pr-10"
            aria-invalid={form.formState.errors.password ? true : undefined}
            aria-describedby={form.formState.errors.password ? "password-error" : undefined}
            {...form.register("password")}
          />
          <button
            type="button"
            onClick={() => setShowPassword((visible) => !visible)}
            aria-label={showPassword ? t("hidePassword") : t("showPassword")}
            aria-pressed={showPassword}
            className="text-muted-foreground hover:text-foreground focus-visible:ring-ring absolute inset-y-0 right-0 flex items-center rounded-md px-3 focus-visible:ring-2 focus-visible:outline-none"
          >
            {showPassword ? (
              <EyeOff aria-hidden="true" className="size-4" />
            ) : (
              <Eye aria-hidden="true" className="size-4" />
            )}
          </button>
        </div>
        {form.formState.errors.password ? (
          <p id="password-error" className="text-destructive text-sm">
            {form.formState.errors.password.message}
          </p>
        ) : null}
      </div>

      <Label htmlFor="remember" className="cursor-pointer font-normal">
        <Checkbox
          id="remember"
          checked={form.watch("remember")}
          onCheckedChange={(checked) => form.setValue("remember", checked === true)}
        />
        {t("rememberMe")}
      </Label>

      <Button type="submit" size="lg" disabled={isSubmitting}>
        {isSubmitting ? t("signingIn") : t("signIn")}
      </Button>
    </form>
  );
}
