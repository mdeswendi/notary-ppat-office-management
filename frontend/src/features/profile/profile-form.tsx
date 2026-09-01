"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { useLocalePreference } from "@/features/profile/use-locale-preference";
import { routing } from "@/i18n/routing";
import { toApiErrorKey } from "@/lib/api/errors";
import { authQueryKeys } from "@/services/auth";
import { getProfile, profileQueryKeys, updateProfile } from "@/services/profile";

const localeLabelKeys = {
  id: "localeIndonesian",
  en: "localeEnglish",
} as const;

/**
 * Your own account.
 *
 * Three editable fields — name, phone, and interface language — and the rest
 * shown as context. Email and Office are rendered as plain read-only text
 * rather than disabled inputs, because a disabled input still looks like a
 * field somebody could argue their way into; text does not. Changing an email
 * needs a verification flow nobody has specified, and moving between Offices is
 * an administrative act (D-066, D-067).
 *
 * Language is saved through the same path the header switcher uses, so the two
 * cannot behave differently (D-064). Saving it navigates to the same page in the
 * new language; saving the rest does not move anywhere.
 *
 * No password, session, or security controls: those are Account Security's, and
 * an incomplete version here would be worse than none.
 */
export function ProfileForm() {
  const t = useTranslations("profile");
  const tCommon = useTranslations("common");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [saved, setSaved] = useState(false);

  const query = useQuery({
    queryKey: profileQueryKeys.profile,
    queryFn: getProfile,
  });

  const preference = useLocalePreference();

  const schema = z.object({
    name: z
      .string()
      .trim()
      .min(1, { message: t("validation.nameRequired") })
      .max(255, { message: t("validation.nameTooLong") }),
    phone: z
      .string()
      .trim()
      .max(50, { message: t("validation.phoneTooLong") }),
  });

  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: "", phone: "" },
  });

  const { reset } = form;
  const loaded = query.data;

  // The form is mounted before the request resolves, so its defaults are filled
  // once the profile arrives. Keyed on the record, not on every render.
  useEffect(() => {
    if (loaded) {
      reset({ name: loaded.name, phone: loaded.phone ?? "" });
    }
  }, [loaded, reset]);

  const mutation = useMutation({
    mutationFn: (values: FormValues) =>
      updateProfile({ name: values.name, phone: values.phone === "" ? null : values.phone }),
    onSuccess: async (profile) => {
      setSaved(true);
      queryClient.setQueryData(profileQueryKeys.profile, profile);

      // The header and account menu show the name, so the session identity is
      // refetched rather than left stale (D-065).
      await queryClient.invalidateQueries({ queryKey: authQueryKeys.me });
    },
    onError: (error: unknown) => {
      form.setError("root", { message: t(`errors.${toApiErrorKey(error)}`) });
    },
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-3" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        {[0, 1, 2].map((row) => (
          <Skeleton key={row} className="h-16 w-full" />
        ))}
      </div>
    );
  }

  if (query.isError || !query.data) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toApiErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  const profile = query.data;

  const onSubmit = form.handleSubmit((values) => {
    setSaved(false);
    form.clearErrors("root");
    mutation.mutate(values);
  });

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader title={t("personalTitle")} description={t("personalDescription")} />

        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
          {form.formState.errors.root ? (
            <p
              role="alert"
              className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
            >
              {form.formState.errors.root.message}
            </p>
          ) : null}

          {saved && !form.formState.isDirty ? (
            <p
              role="status"
              className="border-border bg-muted/40 rounded-md border px-3 py-2 text-sm"
            >
              {t("saved")}
            </p>
          ) : null}

          <div className="flex flex-col gap-2">
            <Label htmlFor="profile-name">{t("nameLabel")}</Label>
            <Input
              id="profile-name"
              autoComplete="name"
              aria-invalid={form.formState.errors.name ? true : undefined}
              aria-describedby={form.formState.errors.name ? "profile-name-error" : undefined}
              {...form.register("name")}
            />
            {form.formState.errors.name ? (
              <p id="profile-name-error" className="text-destructive text-sm">
                {form.formState.errors.name.message}
              </p>
            ) : null}
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="profile-phone">{t("phoneLabel")}</Label>
            <Input
              id="profile-phone"
              autoComplete="tel"
              aria-invalid={form.formState.errors.phone ? true : undefined}
              aria-describedby={form.formState.errors.phone ? "profile-phone-error" : undefined}
              {...form.register("phone")}
            />
            <p className="text-muted-foreground text-xs">{t("phoneHint")}</p>
            {form.formState.errors.phone ? (
              <p id="profile-phone-error" className="text-destructive text-sm">
                {form.formState.errors.phone.message}
              </p>
            ) : null}
          </div>

          {/* Read-only context, rendered as text rather than a disabled input:
              a disabled field still reads as "editable, just not now". */}
          <div className="flex flex-col gap-1">
            <span className="text-sm font-medium">{t("emailLabel")}</span>
            <p className="text-muted-foreground text-sm">{profile.email}</p>
            <p className="text-muted-foreground text-xs">{t("emailReadOnly")}</p>
          </div>

          <div>
            <Button type="submit" disabled={mutation.isPending}>
              {mutation.isPending ? tActions("saving") : tActions("save")}
            </Button>
          </div>
        </form>
      </Card>

      {/* This section carried `gap-3` where its two siblings carried `gap-4`.
          Adopting Card normalises it — a 4px change nobody chose, and the kind of
          drift a shared shell exists to stop. */}
      <Card>
        <CardHeader title={t("workTitle")} description={t("workDescription")} />

        <dl className="flex flex-col gap-3 text-sm">
          <div className="flex flex-col gap-0.5">
            <dt className="font-medium">{t("officeLabel")}</dt>
            <dd className="text-muted-foreground">
              {profile.office ? `${profile.office.code} — ${profile.office.name}` : "—"}
            </dd>
          </div>

          <div className="flex flex-col gap-0.5">
            <dt className="font-medium">{t("rolesLabel")}</dt>
            <dd className="text-muted-foreground">
              {profile.roles.length > 0 ? profile.roles.join(", ") : t("noRoles")}
            </dd>
          </div>
        </dl>
      </Card>

      <Card id="preferences" className="scroll-mt-6">
        <CardHeader title={t("languageTitle")} description={t("languageDescription")} />

        {preference.isError ? (
          <p
            role="alert"
            className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
          >
            {tCommon("languageSaveFailed")}
          </p>
        ) : null}

        <div className="flex flex-wrap gap-2">
          {routing.locales.map((locale) => {
            const isCurrent = profile.preferred_locale === locale;

            return (
              <Button
                key={locale}
                type="button"
                variant={isCurrent ? "default" : "outline"}
                lang={locale}
                aria-pressed={isCurrent}
                disabled={preference.isPending}
                onClick={() => preference.mutate(locale)}
              >
                {tCommon(localeLabelKeys[locale])}
              </Button>
            );
          })}
        </div>

        <p className="text-muted-foreground text-xs">{t("languageHint")}</p>
      </Card>
    </div>
  );
}
