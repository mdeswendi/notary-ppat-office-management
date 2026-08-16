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
import { toCompanyErrorKey } from "@/features/companies/company-errors";
import {
  DuplicateAdvisoryPanel,
  DuplicateCheckNotice,
  useDuplicateAdvisory,
} from "@/features/parties/duplicate-advisory";
import { useRouter } from "@/i18n/navigation";
import {
  companyQueryKeys,
  createCompany,
  getCompanyOptions,
  updateCompany,
} from "@/services/companies";
import {
  checkCompanyDuplicatesForCreate,
  checkCompanyDuplicatesForUpdate,
} from "@/services/party-duplicates";
import { COMPANY_ENTITY_TYPES, type Company, type CompanyEntityType } from "@/types/company";

/**
 * Create and edit a Company's ordinary profile.
 *
 * **Carries no tax identity field.** `tax_id` lives on the identity section under
 * its own permission, and the backend rejects it here outright (D-082). A profile
 * form that also took the tax identifier would quietly make `companies.update` a
 * superset of `parties.identity.update`.
 *
 * Entity type comes from the options endpoint, falling back to the compiled-in
 * code list if that request has not landed — the codes are stable and mirrored in
 * `types/company.ts`, so the control is never empty. Labels are translated at
 * render time; the value posted is always the code (CLAUDE.md section 12).
 *
 * Office is chosen only on create, from the options endpoint, which returns
 * exactly the Offices this actor may create in — so the dropdown cannot offer a
 * destination the Policy would then refuse. On edit there is no Office control at
 * all: transferring a Party between Offices is not designed, and the backend
 * refuses it (D-080).
 *
 * Zod covers shape and length for usability. The Form Request stays authoritative,
 * and no corporate-law rule appears on either side — no required director, no
 * unique registration number, no capital rule. Those are legal rules this
 * milestone has no authority to invent.
 *
 * **Duplicate assistance is advisory and cannot block a save (M2.5, D-084).** The
 * check runs once before the first submit; if it finds anything, a neutral panel
 * offers Review or Continue anyway, and continuing performs the ordinary create
 * or update Action unchanged. A check that fails lets the save through
 * immediately. Nothing here merges, replaces, or reuses a candidate record.
 *
 * It compares **only the ordinary fields this form collects**. `tax_id` is not
 * sent — M2.3 deliberately keeps it on the identity surface, and putting it here
 * to improve matching would make `companies.update` a superset of
 * `parties.identity.update`. Tax-identifier matching belongs to the identity
 * section, under the NPWP full-view capability.
 */
export function CompanyForm({ company }: { company?: Company }) {
  const t = useTranslations("companies");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const isEdit = company !== undefined;

  const advisory = useDuplicateAdvisory();

  const options = useQuery({
    queryKey: companyQueryKeys.options,
    queryFn: getCompanyOptions,
    // Only create needs an Office choice; entity types are compiled in as well.
    enabled: !isEdit,
  });

  const schema = z.object({
    office_id: isEdit
      ? z.string().optional()
      : z.string().min(1, { message: t("validation.officeRequired") }),
    legal_name: z
      .string()
      .trim()
      .min(1, { message: t("validation.legalNameRequired") })
      .max(255, { message: t("validation.tooLong") }),
    short_name: z
      .string()
      .trim()
      .max(255, { message: t("validation.tooLong") }),
    entity_type: z.enum(COMPANY_ENTITY_TYPES, {
      message: t("validation.entityTypeRequired"),
    }),
    registration_number: z
      .string()
      .trim()
      .max(255, { message: t("validation.tooLong") }),
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
      legal_name: "",
      short_name: "",
      entity_type: "PT",
      registration_number: "",
      primary_phone: "",
      primary_email: "",
      address: "",
      city: "",
      province: "",
      postal_code: "",
    },
  });

  const { reset } = form;

  useEffect(() => {
    if (company) {
      reset({
        office_id: company.office?.id ?? "",
        legal_name: company.legal_name,
        short_name: company.short_name ?? "",
        entity_type: company.entity_type ?? "PT",
        registration_number: company.registration_number ?? "",
        primary_phone: company.primary_phone ?? "",
        primary_email: company.primary_email ?? "",
        address: company.address ?? "",
        city: company.city ?? "",
        province: company.province ?? "",
        postal_code: company.postal_code ?? "",
      });
    }
  }, [company, reset]);

  /**
   * Empty strings become null so an untouched optional field stays absent.
   *
   * For `short_name` this is load-bearing rather than tidy: clearing the field
   * sends null, which removes the short name and makes the display name fall
   * back to the legal name (D-079).
   */
  const payload = (values: FormValues) => {
    const blank = (value: string) => (value.trim() === "" ? null : value.trim());

    return {
      legal_name: values.legal_name.trim(),
      short_name: blank(values.short_name),
      entity_type: values.entity_type,
      registration_number: blank(values.registration_number),
      primary_phone: blank(values.primary_phone),
      primary_email: blank(values.primary_email),
      address: blank(values.address),
      city: blank(values.city),
      province: blank(values.province),
      postal_code: blank(values.postal_code),
    };
  };

  const mutation = useMutation({
    mutationFn: async (values: FormValues) => {
      if (company) {
        return updateCompany(company.id, payload(values));
      }

      return createCompany({ office_id: values.office_id ?? "", ...payload(values) });
    },
    onSuccess: async (saved) => {
      await queryClient.invalidateQueries({ queryKey: companyQueryKeys.all });

      router.push(`/parties/companies/${saved.id}`);
    },
    onError: (error: unknown) => {
      form.setError("root", { message: t(`errors.${toCompanyErrorKey(error)}`) });
    },
  });

  /**
   * The check to run for these values, or null when there is nothing to compare.
   *
   * Ordinary signals only — legal name, registration number, email, phone — and
   * only the ones actually filled in. No `tax_id`: it is not on this form, and
   * asking about it requires the NPWP full-view capability rather than
   * `companies.create`.
   */
  const duplicateCheck = (values: FormValues) => {
    const filled = (value: string) => (value.trim() === "" ? undefined : value.trim());

    const comparison = {
      legal_name: filled(values.legal_name),
      registration_number: filled(values.registration_number),
      primary_email: filled(values.primary_email),
      primary_phone: filled(values.primary_phone),
    };

    if (Object.values(comparison).every((value) => value === undefined)) {
      return null;
    }

    if (company) {
      return () => checkCompanyDuplicatesForUpdate(company.id, comparison);
    }

    const officeId = values.office_id ?? "";

    if (officeId === "") {
      return null;
    }

    return () => checkCompanyDuplicatesForCreate({ office_id: officeId, ...comparison });
  };

  const onSubmit = form.handleSubmit(async (values) => {
    form.clearErrors("root");

    // Advisory: this delays the save exactly once and only when something was
    // found. Acknowledging, or a failed check, lets every later submit through.
    if (!(await advisory.gate(duplicateCheck(values)))) {
      return;
    }

    mutation.mutate(values);
  });

  const entityTypes: readonly CompanyEntityType[] =
    options.data?.entity_types ?? COMPANY_ENTITY_TYPES;

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
          <select
            id="office_id"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            {...form.register("office_id")}
          >
            <option value="">
              {options.isPending ? t("officeLoading") : t("officePlaceholder")}
            </option>
            {(options.data?.offices ?? []).map((office) => (
              <option key={office.id} value={office.id}>
                {office.code} — {office.name}
              </option>
            ))}
          </select>
          {form.formState.errors.office_id ? (
            <p className="text-destructive text-sm">{form.formState.errors.office_id.message}</p>
          ) : null}
        </div>
      ) : null}

      <Field
        id="legal_name"
        label={t("legalNameLabel")}
        description={t("legalNameHint")}
        registration={form.register("legal_name")}
        error={form.formState.errors.legal_name?.message}
      />

      <Field
        id="short_name"
        label={t("shortNameLabel")}
        description={t("shortNameHint")}
        registration={form.register("short_name")}
        error={form.formState.errors.short_name?.message}
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2">
          <Label htmlFor="entity_type">{t("entityTypeLabel")}</Label>
          <select
            id="entity_type"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            {...form.register("entity_type")}
          >
            {entityTypes.map((code) => (
              <option key={code} value={code}>
                {t(`entityTypes.${code}`)}
              </option>
            ))}
          </select>
          {form.formState.errors.entity_type ? (
            <p className="text-destructive text-sm">{form.formState.errors.entity_type.message}</p>
          ) : null}
        </div>

        <Field
          id="registration_number"
          label={t("registrationNumberLabel")}
          registration={form.register("registration_number")}
          error={form.formState.errors.registration_number?.message}
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
         * was found.
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
  description,
  type = "text",
}: {
  id: string;
  label: string;
  registration: UseFormRegisterReturn;
  error?: string;
  description?: string;
  type?: string;
}) {
  const describedBy = [error ? `${id}-error` : null, description ? `${id}-hint` : null]
    .filter(Boolean)
    .join(" ");

  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type={type}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy === "" ? undefined : describedBy}
        {...registration}
      />
      {description ? (
        <p id={`${id}-hint`} className="text-muted-foreground text-sm">
          {description}
        </p>
      ) : null}
      {error ? (
        <p id={`${id}-error`} className="text-destructive text-sm">
          {error}
        </p>
      ) : null}
    </div>
  );
}
