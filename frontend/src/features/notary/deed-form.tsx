"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useForm, type UseFormRegisterReturn } from "react-hook-form";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { hasFieldError, toNotaryErrorKey } from "@/features/notary/deed-errors";
import { useRouter } from "@/i18n/navigation";
import { createNotaryDeed, getNotaryDeedOptions, notaryDeedKeys } from "@/services/notary";

/**
 * Record a Notarial Deed (M6.2, D-120).
 *
 * **Four things this form deliberately does not offer**, each because the backend
 * refuses it outright rather than ignoring it:
 *
 * ```text
 * Office        inherited from the parent Matter, never chosen.
 * Status        a new deed is DRAFT; review, approval and finalization are their
 *               own capabilities with their own buttons on the detail page.
 * Deed number   its own capability, `notary.deeds.number`, on the detail page.
 * The act pairs a deed cannot have been approved before it exists.
 * ```
 *
 * A form that showed those and silently dropped them would tell the user their
 * choice was accepted. The API returns 422 for each.
 *
 * **The deed number is the one worth explaining**, because a brief asked for it here.
 * Numbering answers to a separate capability precisely so the office decides *when*
 * a deed is numbered — tying it to creation would answer half of *"who assigns the
 * number, and when?"*, an open domain question (D-120). Somebody who may record
 * numbers does so from the deed's own page, at whatever point their office does it.
 *
 * **Neither the date nor the type code is required.** A deed being drafted has not
 * been executed, so it has no date yet; and `deed_type_code` is opaque with no
 * catalogue behind it, so requiring it would make deeds unrecordable.
 *
 * **The Matter list comes from the server** and contains only Matters creation would
 * accept — Notary, in the actor's own Office, reachable under `notary.matters.view`.
 * Offering one the endpoint would refuse is a dead control.
 */
export function DeedForm({ matterId }: { matterId?: string }) {
  const t = useTranslations("notary");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const options = useQuery({
    queryKey: notaryDeedKeys.options(),
    queryFn: getNotaryDeedOptions,
  });

  const schema = z.object({
    matter_id: z
      .string()
      .trim()
      .min(1, { message: t("validation.matterRequired") }),
    title: z
      .string()
      .trim()
      .min(1, { message: t("validation.titleRequired") })
      .max(255, { message: t("validation.tooLong") }),
    deed_date: z.string().trim(),
    deed_type_code: z
      .string()
      .trim()
      .max(50, { message: t("validation.tooLong") }),
  });

  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      matter_id: matterId ?? "",
      title: "",
      deed_date: "",
      deed_type_code: "",
    },
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const blank = (value: string) => (value.trim() === "" ? null : value.trim());

      return createNotaryDeed({
        matter_id: values.matter_id,
        title: values.title.trim(),
        deed_date: blank(values.deed_date),
        deed_type_code: blank(values.deed_type_code),
      });
    },
    onSuccess: async (saved) => {
      await queryClient.invalidateQueries({ queryKey: notaryDeedKeys.all() });

      router.push(`/notary/deeds/${saved.id}`);
    },
    onError: (error: unknown) => {
      // The server knows what the browser cannot: whether the Matter is reachable,
      // of the Notary domain, and in this Office. All three answer alike, so the
      // field error is shown against the picker rather than as a general failure.
      if (hasFieldError(error, "matter_id")) {
        form.setError("matter_id", { message: t("validation.matterUnavailable") });

        return;
      }

      form.setError("root", { message: t(`errors.${toNotaryErrorKey(error)}`) });
    },
  });

  const onSubmit = form.handleSubmit((values) => {
    form.clearErrors("root");
    mutation.mutate(values);
  });

  return (
    <form onSubmit={onSubmit} noValidate className="flex max-w-2xl flex-col gap-6">
      {form.formState.errors.root ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {form.formState.errors.root.message}
        </p>
      ) : null}

      <p className="text-muted-foreground text-sm">{t("deedCreateHint")}</p>

      <div className="flex flex-col gap-2">
        <Label htmlFor="matter_id">{t("matterLabel")}</Label>
        <Select
          id="matter_id"
          aria-invalid={form.formState.errors.matter_id !== undefined}
          disabled={matterId !== undefined}
          {...form.register("matter_id")}
        >
          <option value="">{t("selectMatter")}</option>
          {(options.data?.matters ?? []).map((matter) => (
            <option key={matter.id} value={matter.id}>
              {matter.matter_number} — {matter.title}
            </option>
          ))}
        </Select>
        {form.formState.errors.matter_id ? (
          <p role="alert" className="text-destructive text-sm">
            {form.formState.errors.matter_id.message}
          </p>
        ) : null}
        <p className="text-muted-foreground text-xs">{t("matterHint")}</p>
      </div>

      <Field
        id="title"
        label={t("deedTitle")}
        registration={form.register("title")}
        error={form.formState.errors.title?.message}
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="deed_date"
          label={t("deedDate")}
          registration={form.register("deed_date")}
          error={form.formState.errors.deed_date?.message}
          type="date"
        />

        <Field
          id="deed_type_code"
          label={t("deedType")}
          registration={form.register("deed_type_code")}
          error={form.formState.errors.deed_type_code?.message}
        />
      </div>

      <p className="text-muted-foreground text-xs">{t("deedNumberHint")}</p>

      <div>
        <Button type="submit" disabled={mutation.isPending}>
          {mutation.isPending ? tActions("saving") : tActions("save")}
        </Button>
      </div>
    </form>
  );
}

function Field({
  id,
  label,
  registration,
  error,
  type = "text",
}: {
  id: string;
  label: string;
  registration: UseFormRegisterReturn;
  error?: string;
  type?: string;
}) {
  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input id={id} type={type} aria-invalid={error !== undefined} {...registration} />
      {error ? (
        <p role="alert" className="text-destructive text-sm">
          {error}
        </p>
      ) : null}
    </div>
  );
}
