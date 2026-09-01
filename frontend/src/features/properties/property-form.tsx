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
import { hasFieldError, toPropertyErrorKey } from "@/features/properties/property-errors";
import { useRouter } from "@/i18n/navigation";
import {
  createProperty,
  getPropertyOptions,
  propertyKeys,
  updateProperty,
} from "@/services/properties";
import { PROPERTY_TYPES, type Property, type PropertyType } from "@/types/property";

/**
 * Record or correct a land object (M7.3, D-121).
 *
 * ## Two vocabularies, two controls, and the difference is the ERD's own wording
 *
 * **`property_type` is a `<select>`.** The ERD gives four values as a flat closed list
 * with no hedging word, the database CHECKs them, and the interface may safely present
 * them as the vocabulary.
 *
 * **`right_type` is a text input with a `datalist`.** The ERD says *"Right type **may**
 * use stable machine codes, **for example**"* — so the six codes are offered as
 * typeahead suggestions and anything else is accepted. A `<select>` here would assert
 * that Indonesian land law has six kinds of right, which `11_LEGAL_REFERENCES.md`
 * exists as a statutory register precisely because nobody here may decide
 * (`CLAUDE.md` section 62).
 *
 * ## Three things this form deliberately does not offer
 *
 * ```text
 * Office     inherited from the actor, never chosen — ALL is reach over records
 *            that exist, not authority to place a new one elsewhere.
 * status     no canonical vocabulary at all; sending it is a 422.
 * Ownership  its own capability on its own surface. Correcting an address must
 *            never be a way to rewrite who owns the land.
 * ```
 *
 * ## `property_number` is required at creation and immutable afterwards
 *
 * Required, because the office's own reference is how it finds the record again;
 * **office-supplied**, because the ERD gives no format and `CLAUDE.md` section 38 shows
 * `PROP-000001` without a year, alone among the internal references. No format is
 * validated and none is suggested — the placeholder is deliberately not an example
 * number, for the reason the deed-number field is not either.
 *
 * On edit the field renders read-only: a reference belongs to the record that received
 * it (D-103), and the API answers 422 rather than ignoring a change.
 */
export function PropertyForm({ property }: { property?: Property }) {
  const t = useTranslations("properties");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const isEdit = property !== undefined;

  const options = useQuery({
    queryKey: propertyKeys.options(),
    queryFn: getPropertyOptions,
  });

  const optionalText = (max: number) =>
    z
      .string()
      .trim()
      .max(max, { message: t("validation.tooLong") });

  const schema = z.object({
    property_number: isEdit
      ? z.string().trim()
      : z
          .string()
          .trim()
          .min(1, { message: t("validation.propertyNumberRequired") })
          .max(50, { message: t("validation.tooLong") }),
    property_type: z.enum(PROPERTY_TYPES),
    right_type: z
      .string()
      .trim()
      .min(1, { message: t("validation.rightTypeRequired") })
      .max(30, { message: t("validation.tooLong") }),
    certificate_number: z
      .string()
      .trim()
      .min(1, { message: t("validation.certificateNumberRequired") })
      .max(100, { message: t("validation.tooLong") }),
    certificate_date: z.string().trim(),
    land_area: z.string().trim(),
    building_area: z.string().trim(),
    measurement_letter_number: optionalText(100),
    measurement_letter_date: z.string().trim(),
    address: z
      .string()
      .trim()
      .min(1, { message: t("validation.addressRequired") })
      .max(2000, { message: t("validation.tooLong") }),
    village: optionalText(255),
    district: optionalText(255),
    city: optionalText(255),
    province: optionalText(255),
    postal_code: optionalText(20),
  });

  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      property_number: property?.property_number ?? "",
      property_type: property?.property_type ?? "LAND",
      right_type: property?.right_type ?? "",
      certificate_number: property?.certificate_number ?? "",
      certificate_date: property?.certificate_date ?? "",
      land_area:
        property?.land_area === null || property === undefined ? "" : String(property.land_area),
      building_area:
        property?.building_area === null || property === undefined
          ? ""
          : String(property.building_area),
      measurement_letter_number: property?.measurement_letter_number ?? "",
      measurement_letter_date: property?.measurement_letter_date ?? "",
      address: property?.address ?? "",
      village: property?.village ?? "",
      district: property?.district ?? "",
      city: property?.city ?? "",
      province: property?.province ?? "",
      postal_code: property?.postal_code ?? "",
    },
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const blank = (value: string) => (value.trim() === "" ? null : value.trim());
      const decimal = (value: string) => (value.trim() === "" ? null : Number(value));

      const payload = {
        property_type: values.property_type as PropertyType,
        right_type: values.right_type.trim(),
        certificate_number: values.certificate_number.trim(),
        certificate_date: blank(values.certificate_date),
        land_area: decimal(values.land_area),
        building_area: decimal(values.building_area),
        measurement_letter_number: blank(values.measurement_letter_number),
        measurement_letter_date: blank(values.measurement_letter_date),
        address: values.address.trim(),
        village: blank(values.village),
        district: blank(values.district),
        city: blank(values.city),
        province: blank(values.province),
        postal_code: blank(values.postal_code),
      };

      // `property_number` is sent only on creation. It is immutable once assigned
      // (D-103), and the API refuses it outright rather than ignoring it.
      return isEdit
        ? updateProperty(property.id, payload)
        : createProperty({ ...payload, property_number: values.property_number.trim() });
    },
    onSuccess: async (saved) => {
      await queryClient.invalidateQueries({ queryKey: propertyKeys.all() });

      router.push(`/ppat/properties/${saved.id}`);
    },
    onError: (error: unknown) => {
      // The server knows what the browser cannot: whether this reference is still
      // free within the Office.
      if (hasFieldError(error, "property_number")) {
        form.setError("property_number", { message: t("validation.propertyNumberTaken") });

        return;
      }

      form.setError("root", { message: t(`errors.${toPropertyErrorKey(error)}`) });
    },
  });

  const onSubmit = form.handleSubmit((values) => {
    form.clearErrors("root");
    mutation.mutate(values);
  });

  return (
    <form onSubmit={onSubmit} noValidate className="flex max-w-3xl flex-col gap-6">
      {form.formState.errors.root ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {form.formState.errors.root.message}
        </p>
      ) : null}

      <p className="text-muted-foreground text-sm">{t("formHint")}</p>

      <fieldset className="flex flex-col gap-4">
        <legend className="text-sm font-medium">{t("sections.identification")}</legend>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-2">
            <Label htmlFor="property_number">{t("propertyNumber")}</Label>
            <Input
              id="property_number"
              readOnly={isEdit}
              aria-invalid={form.formState.errors.property_number !== undefined}
              {...form.register("property_number")}
            />
            {form.formState.errors.property_number ? (
              <p role="alert" className="text-destructive text-sm">
                {form.formState.errors.property_number.message}
              </p>
            ) : null}
            <p className="text-muted-foreground text-xs">
              {isEdit ? t("propertyNumberImmutable") : t("propertyNumberHint")}
            </p>
          </div>

          <Field
            id="certificate_number"
            label={t("certificateNumber")}
            registration={form.register("certificate_number")}
            error={form.formState.errors.certificate_number?.message}
            hint={t("certificateNumberHint")}
          />
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-2">
            <Label htmlFor="property_type">{t("propertyType")}</Label>
            <Select
              id="property_type"
              aria-invalid={form.formState.errors.property_type !== undefined}
              {...form.register("property_type")}
            >
              {PROPERTY_TYPES.map((code) => (
                <option key={code} value={code}>
                  {t(`propertyTypes.${code}`)}
                </option>
              ))}
            </Select>
          </div>

          {/*
            A text input with a datalist, not a select. The six codes are the ERD's
            examples, and anything the office types is accepted — see the component
            docblock.
          */}
          <div className="flex flex-col gap-2">
            <Label htmlFor="right_type">{t("rightType")}</Label>
            <Input
              id="right_type"
              list="right-type-examples"
              aria-invalid={form.formState.errors.right_type !== undefined}
              {...form.register("right_type")}
            />
            <datalist id="right-type-examples">
              {(options.data?.right_type_examples ?? []).map((code) => (
                <option key={code} value={code} />
              ))}
            </datalist>
            {form.formState.errors.right_type ? (
              <p role="alert" className="text-destructive text-sm">
                {form.formState.errors.right_type.message}
              </p>
            ) : null}
            <p className="text-muted-foreground text-xs">{t("rightTypeHint")}</p>
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-3">
          <Field
            id="certificate_date"
            label={t("certificateDate")}
            registration={form.register("certificate_date")}
            error={form.formState.errors.certificate_date?.message}
            type="date"
          />
          <Field
            id="measurement_letter_number"
            label={t("measurementLetterNumber")}
            registration={form.register("measurement_letter_number")}
            error={form.formState.errors.measurement_letter_number?.message}
          />
          <Field
            id="measurement_letter_date"
            label={t("measurementLetterDate")}
            registration={form.register("measurement_letter_date")}
            error={form.formState.errors.measurement_letter_date?.message}
            type="date"
          />
        </div>
      </fieldset>

      <fieldset className="flex flex-col gap-4">
        <legend className="text-sm font-medium">{t("sections.measurements")}</legend>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            id="land_area"
            label={t("landArea")}
            registration={form.register("land_area")}
            error={form.formState.errors.land_area?.message}
            type="number"
          />
          <Field
            id="building_area"
            label={t("buildingArea")}
            registration={form.register("building_area")}
            error={form.formState.errors.building_area?.message}
            type="number"
          />
        </div>
      </fieldset>

      <fieldset className="flex flex-col gap-4">
        <legend className="text-sm font-medium">{t("sections.location")}</legend>

        <Field
          id="address"
          label={t("address")}
          registration={form.register("address")}
          error={form.formState.errors.address?.message}
        />

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            id="village"
            label={t("village")}
            registration={form.register("village")}
            error={form.formState.errors.village?.message}
          />
          <Field
            id="district"
            label={t("district")}
            registration={form.register("district")}
            error={form.formState.errors.district?.message}
          />
          <Field
            id="city"
            label={t("city")}
            registration={form.register("city")}
            error={form.formState.errors.city?.message}
          />
          <Field
            id="province"
            label={t("province")}
            registration={form.register("province")}
            error={form.formState.errors.province?.message}
          />
          <Field
            id="postal_code"
            label={t("postalCode")}
            registration={form.register("postal_code")}
            error={form.formState.errors.postal_code?.message}
          />
        </div>
      </fieldset>

      <p className="text-muted-foreground text-xs">{t("ownershipElsewhereHint")}</p>

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
  hint,
  type = "text",
}: {
  id: string;
  label: string;
  registration: UseFormRegisterReturn;
  error?: string;
  hint?: string;
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
      {hint ? <p className="text-muted-foreground text-xs">{hint}</p> : null}
    </div>
  );
}
