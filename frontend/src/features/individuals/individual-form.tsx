"use client";

import { useEffect } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useForm, type UseFormRegisterReturn } from "react-hook-form";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { toIndividualErrorKey } from "@/features/individuals/individual-errors";
import {
  DuplicateAdvisoryPanel,
  DuplicateCheckNotice,
  useDuplicateAdvisory,
} from "@/features/parties/duplicate-advisory";
import { useRouter } from "@/i18n/navigation";
import {
  checkIndividualDuplicatesForCreate,
  checkIndividualDuplicatesForUpdate,
} from "@/services/party-duplicates";
import {
  createIndividual,
  getIndividualOptions,
  individualQueryKeys,
  updateIndividual,
} from "@/services/individuals";
import type { Individual } from "@/types/individual";

/**
 * Create and edit an Individual's ordinary profile.
 *
 * **Carries no identity fields.** NIK and NPWP live on the identity section under
 * their own permission, and the backend rejects them here outright (D-082). A
 * profile form that also took identity would quietly make `parties.update` a
 * superset of `parties.identity.update`.
 *
 * Office is chosen only on create, from the options endpoint, which returns
 * exactly the Offices this actor may create in — so the dropdown cannot offer a
 * destination the Policy would then refuse. On edit there is no Office control at
 * all: transferring a Party between Offices is not designed, and the backend
 * refuses it (D-080).
 *
 * Zod covers shape and length for usability. The Form Request stays authoritative,
 * and no legal format rule appears on either side — M2.0 deferred those, and this
 * is one of the places somebody would be tempted to add one from memory.
 *
 * **Duplicate assistance is advisory and cannot block a save (M2.5, D-084).** The
 * check runs once before the first submit; if it finds anything, a neutral panel
 * offers Review or Continue anyway, and continuing performs the ordinary create
 * or update Action unchanged. A check that fails — refused, rate limited, or
 * unreachable — lets the save through immediately, because assistance failing
 * must never stop legitimate work. Nothing here merges, replaces, or reuses a
 * candidate record.
 *
 * It compares **only the ordinary fields this form collects**. NIK and NPWP are
 * not sent: they are not on this form, and asking about one requires that
 * identifier's own full-view capability, which `parties.create` is not.
 */
export function IndividualForm({ individual }: { individual?: Individual }) {
  const t = useTranslations("individuals");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const isEdit = individual !== undefined;

  const advisory = useDuplicateAdvisory();

  const options = useQuery({
    queryKey: individualQueryKeys.options,
    queryFn: getIndividualOptions,
    // Only create needs an Office choice.
    enabled: !isEdit,
  });

  const schema = z.object({
    office_id: isEdit
      ? z.string().optional()
      : z.string().min(1, { message: t("validation.officeRequired") }),
    full_name: z
      .string()
      .trim()
      .min(1, { message: t("validation.nameRequired") })
      .max(255, { message: t("validation.nameTooLong") }),
    prefix: z
      .string()
      .trim()
      .max(50, { message: t("validation.tooLong") }),
    suffix: z
      .string()
      .trim()
      .max(50, { message: t("validation.tooLong") }),
    primary_phone: z
      .string()
      .trim()
      .max(50, { message: t("validation.tooLong") }),
    primary_email: z
      .string()
      .trim()
      .max(255, { message: t("validation.tooLong") })
      .refine((value) => value === "" || /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value), {
        message: t("validation.emailInvalid"),
      }),
    birth_place: z
      .string()
      .trim()
      .max(255, { message: t("validation.tooLong") }),
    birth_date: z.string().trim(),
    occupation: z
      .string()
      .trim()
      .max(255, { message: t("validation.tooLong") }),
    nationality: z
      .string()
      .trim()
      .max(100, { message: t("validation.tooLong") }),
    address: z
      .string()
      .trim()
      .max(255, { message: t("validation.tooLong") }),
    city: z
      .string()
      .trim()
      .max(255, { message: t("validation.tooLong") }),
    province: z
      .string()
      .trim()
      .max(255, { message: t("validation.tooLong") }),
    postal_code: z
      .string()
      .trim()
      .max(20, { message: t("validation.tooLong") }),
  });

  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      office_id: "",
      full_name: "",
      prefix: "",
      suffix: "",
      primary_phone: "",
      primary_email: "",
      birth_place: "",
      birth_date: "",
      occupation: "",
      nationality: "",
      address: "",
      city: "",
      province: "",
      postal_code: "",
    },
  });

  const { reset } = form;

  useEffect(() => {
    if (individual) {
      reset({
        office_id: individual.office?.id ?? "",
        full_name: individual.full_name,
        prefix: individual.prefix ?? "",
        suffix: individual.suffix ?? "",
        primary_phone: individual.primary_phone ?? "",
        primary_email: individual.primary_email ?? "",
        birth_place: individual.birth_place ?? "",
        birth_date: individual.birth_date ?? "",
        occupation: individual.occupation ?? "",
        nationality: individual.nationality ?? "",
        address: individual.address ?? "",
        city: individual.city ?? "",
        province: individual.province ?? "",
        postal_code: individual.postal_code ?? "",
      });
    }
  }, [individual, reset]);

  /** Empty strings become null so an untouched optional field stays absent. */
  const payload = (values: FormValues) => {
    const blank = (value: string) => (value.trim() === "" ? null : value.trim());

    return {
      full_name: values.full_name.trim(),
      prefix: blank(values.prefix),
      suffix: blank(values.suffix),
      primary_phone: blank(values.primary_phone),
      primary_email: blank(values.primary_email),
      birth_place: blank(values.birth_place),
      birth_date: blank(values.birth_date),
      occupation: blank(values.occupation),
      nationality: blank(values.nationality),
      address: blank(values.address),
      city: blank(values.city),
      province: blank(values.province),
      postal_code: blank(values.postal_code),
    };
  };

  const mutation = useMutation({
    mutationFn: async (values: FormValues) => {
      if (individual) {
        return updateIndividual(individual.id, payload(values));
      }

      return createIndividual({ office_id: values.office_id ?? "", ...payload(values) });
    },
    onSuccess: async (saved) => {
      await queryClient.invalidateQueries({ queryKey: individualQueryKeys.all });

      router.push(`/parties/individuals/${saved.id}`);
    },
    onError: (error: unknown) => {
      form.setError("root", { message: t(`errors.${toIndividualErrorKey(error)}`) });
    },
  });

  /**
   * The check to run for these values, or null when there is nothing to compare.
   *
   * Ordinary signals only, and only the ones actually filled in — an empty form
   * asks nothing, which keeps the endpoint from being used as a way to page
   * through the directory. On create the chosen Office bounds the comparison; on
   * update the backend takes it from the record and rejects the field, and
   * excludes the subject itself so a record never matches itself.
   */
  const duplicateCheck = (values: FormValues) => {
    const filled = (value: string) => (value.trim() === "" ? undefined : value.trim());

    const comparison = {
      full_name: filled(values.full_name),
      birth_date: filled(values.birth_date),
      primary_email: filled(values.primary_email),
      primary_phone: filled(values.primary_phone),
    };

    if (Object.values(comparison).every((value) => value === undefined)) {
      return null;
    }

    if (individual) {
      return () => checkIndividualDuplicatesForUpdate(individual.id, comparison);
    }

    const officeId = values.office_id ?? "";

    // Without an Office there is no target to compare against, and the endpoint
    // requires one. Zod has already flagged the field.
    if (officeId === "") {
      return null;
    }

    return () => checkIndividualDuplicatesForCreate({ office_id: officeId, ...comparison });
  };

  const onSubmit = form.handleSubmit(async (values) => {
    form.clearErrors("root");

    // Advisory, so this delays the save exactly once and only when something was
    // found. Acknowledging, or a failed check, lets every later submit straight
    // through — the panel never becomes a permanent gate.
    if (!(await advisory.gate(duplicateCheck(values)))) {
      return;
    }

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

      <DuplicateAdvisoryPanel
        candidates={advisory.candidates}
        onReview={advisory.dismiss}
        continueDisabled={mutation.isPending}
        onContinue={() => {
          advisory.acknowledge();
          void onSubmit();
        }}
      />

      <DuplicateCheckNotice reason={advisory.unavailable} />

      {!isEdit ? (
        <div className="flex flex-col gap-2">
          <Label htmlFor="office_id">{t("officeLabel")}</Label>
          <Select id="office_id" {...form.register("office_id")}>
            <option value="">
              {options.isPending ? t("officeLoading") : t("officePlaceholder")}
            </option>
            {(options.data?.offices ?? []).map((office) => (
              <option key={office.id} value={office.id}>
                {office.code} — {office.name}
              </option>
            ))}
          </Select>
          {form.formState.errors.office_id ? (
            <p className="text-destructive text-sm">{form.formState.errors.office_id.message}</p>
          ) : null}
        </div>
      ) : null}

      <Field
        id="full_name"
        label={t("nameLabel")}
        registration={form.register("full_name")}
        error={form.formState.errors.full_name?.message}
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="prefix"
          label={t("prefixLabel")}
          registration={form.register("prefix")}
          error={form.formState.errors.prefix?.message}
        />
        <Field
          id="suffix"
          label={t("suffixLabel")}
          registration={form.register("suffix")}
          error={form.formState.errors.suffix?.message}
        />
      </div>

      <fieldset className="flex flex-col gap-4">
        <legend className="text-sm font-medium">{t("contactSection")}</legend>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            id="primary_phone"
            label={t("phoneLabel")}
            registration={form.register("primary_phone")}
            error={form.formState.errors.primary_phone?.message}
          />
          <Field
            id="primary_email"
            label={t("emailLabel")}
            registration={form.register("primary_email")}
            error={form.formState.errors.primary_email?.message}
          />
        </div>
      </fieldset>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="birth_place"
          label={t("birthPlaceLabel")}
          registration={form.register("birth_place")}
          error={form.formState.errors.birth_place?.message}
        />
        <Field
          id="birth_date"
          label={t("birthDateLabel")}
          registration={form.register("birth_date")}
          error={form.formState.errors.birth_date?.message}
          type="date"
        />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="occupation"
          label={t("occupationLabel")}
          registration={form.register("occupation")}
          error={form.formState.errors.occupation?.message}
        />
        <Field
          id="nationality"
          label={t("nationalityLabel")}
          registration={form.register("nationality")}
          error={form.formState.errors.nationality?.message}
        />
      </div>

      <fieldset className="flex flex-col gap-4">
        <legend className="text-sm font-medium">{t("addressSection")}</legend>
        <Field
          id="address"
          label={t("addressLabel")}
          registration={form.register("address")}
          error={form.formState.errors.address?.message}
        />
        <div className="grid gap-4 sm:grid-cols-3">
          <Field
            id="city"
            label={t("cityLabel")}
            registration={form.register("city")}
            error={form.formState.errors.city?.message}
          />
          <Field
            id="province"
            label={t("provinceLabel")}
            registration={form.register("province")}
            error={form.formState.errors.province?.message}
          />
          <Field
            id="postal_code"
            label={t("postalCodeLabel")}
            registration={form.register("postal_code")}
            error={form.formState.errors.postal_code?.message}
          />
        </div>
      </fieldset>

      <div>
        {/*
         * Disabled only while a request is in flight — never because a candidate
         * was found. A duplicate warning must not be able to make Save
         * permanently unavailable.
         */}
        <Button type="submit" disabled={mutation.isPending || advisory.checking}>
          {advisory.checking
            ? t("checkingDuplicates")
            : mutation.isPending
              ? tActions("saving")
              : tActions("save")}
        </Button>
      </div>
    </form>
  );
}

/**
 * One labelled text input.
 *
 * Takes the registration and the message rather than the whole form object,
 * which keeps it fully typed — passing `UseFormReturn` through a generic
 * boundary is where an `any` usually creeps in, and CLAUDE.md section 53 rules
 * that out.
 */
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
      <Input
        id={id}
        type={type}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${id}-error` : undefined}
        {...registration}
      />
      {error ? (
        <p id={`${id}-error`} className="text-destructive text-sm">
          {error}
        </p>
      ) : null}
    </div>
  );
}
