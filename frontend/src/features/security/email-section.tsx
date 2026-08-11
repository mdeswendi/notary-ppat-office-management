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
import { authQueryKeys } from "@/services/auth";
import { cancelEmailChange, requestEmailChange, securityQueryKeys } from "@/services/security";
import { profileQueryKeys } from "@/services/profile";
import type { SecurityOverview } from "@/types/security";

/**
 * Change the address you sign in with.
 *
 * The current address stays in force until the new one is confirmed, and the
 * interface says so plainly. That sentence is the whole reason the flow has two
 * steps: a person who mistypes their address must not lose the account.
 *
 * When a change is pending, this section shows which mailbox to check and offers
 * a way to abandon it — the answer to both "I typed it wrong" and "I did not ask
 * for this".
 */
export function EmailSection({ overview }: { overview: SecurityOverview }) {
  const t = useTranslations("security");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [sent, setSent] = useState(false);

  const schema = z.object({
    current_password: z.string().min(1, { message: t("validation.currentPasswordRequired") }),
    email: z
      .string()
      .trim()
      .min(1, { message: t("validation.emailRequired") })
      .email({ message: t("validation.emailInvalid") }),
  });

  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { current_password: "", email: "" },
  });

  const store = (data: SecurityOverview) => {
    queryClient.setQueryData(securityQueryKeys.overview, data);
  };

  const request = useMutation({
    mutationFn: (values: FormValues) => requestEmailChange(values),
    onSuccess: (data) => {
      setSent(true);
      store(data);
      form.reset({ current_password: "", email: "" });
    },
    onError: (error: unknown) => {
      form.setError("root", { message: t(`errors.${toApiErrorKey(error)}`) });
      form.resetField("current_password");
    },
  });

  const cancel = useMutation({
    mutationFn: cancelEmailChange,
    onSuccess: async (data) => {
      setSent(false);
      store(data);

      // The address did not change, but the header and profile read the same
      // record, so both are refreshed rather than left to diverge.
      await queryClient.invalidateQueries({ queryKey: profileQueryKeys.profile });
      await queryClient.invalidateQueries({ queryKey: authQueryKeys.me });
    },
  });

  const onSubmit = form.handleSubmit((values) => {
    setSent(false);
    form.clearErrors("root");
    request.mutate(values);
  });

  return (
    <SecuritySection title={t("emailTitle")} description={t("emailDescription")}>
      <div className="flex flex-col gap-1">
        <span className="text-sm font-medium">{t("currentEmailLabel")}</span>
        <p className="text-muted-foreground text-sm">{overview.email}</p>
      </div>

      {overview.pending_email ? (
        <div className="border-border bg-muted/40 flex flex-col items-start gap-3 rounded-md border px-3 py-3">
          <div className="flex flex-col gap-1">
            <p className="text-sm font-medium">{t("emailPendingTitle")}</p>
            <p className="text-muted-foreground text-sm">
              {t("emailPendingDescription", { email: overview.pending_email })}
            </p>
          </div>

          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={cancel.isPending}
            onClick={() => cancel.mutate()}
          >
            {t("cancelEmailChange")}
          </Button>
        </div>
      ) : null}

      <form onSubmit={onSubmit} noValidate className="flex max-w-md flex-col gap-4">
        {form.formState.errors.root ? (
          <SecurityError>{form.formState.errors.root.message}</SecurityError>
        ) : null}

        {sent ? <SecurityNotice>{t("emailVerificationSent")}</SecurityNotice> : null}

        <div className="flex flex-col gap-2">
          <Label htmlFor="new-email">{t("newEmailLabel")}</Label>
          <Input
            id="new-email"
            type="email"
            autoComplete="email"
            aria-invalid={form.formState.errors.email ? true : undefined}
            aria-describedby={form.formState.errors.email ? "new-email-error" : undefined}
            {...form.register("email")}
          />
          {form.formState.errors.email ? (
            <p id="new-email-error" className="text-destructive text-sm">
              {form.formState.errors.email.message}
            </p>
          ) : null}
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor="email-current-password">{t("currentPasswordLabel")}</Label>
          <Input
            id="email-current-password"
            type="password"
            autoComplete="current-password"
            aria-invalid={form.formState.errors.current_password ? true : undefined}
            {...form.register("current_password")}
          />
          {form.formState.errors.current_password ? (
            <p className="text-destructive text-sm">
              {form.formState.errors.current_password.message}
            </p>
          ) : null}
        </div>

        <p className="text-muted-foreground text-xs">{t("emailUnchangedUntilConfirmed")}</p>

        <div>
          <Button type="submit" disabled={request.isPending}>
            {request.isPending ? tActions("saving") : t("sendVerification")}
          </Button>
        </div>
      </form>
    </SecuritySection>
  );
}
