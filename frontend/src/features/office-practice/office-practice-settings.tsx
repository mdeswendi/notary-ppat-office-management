"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { InlineAlert } from "@/components/feedback/inline-alert";
import { FormActions } from "@/components/forms/form-actions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
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
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { toOfficePracticeErrorKey } from "@/features/office-practice/office-practice-errors";
import { authQueryKeys } from "@/services/auth";
import {
  addProfessionalAppointment,
  endProfessionalAppointment,
  getOfficePractice,
  getOfficePracticeOptions,
  officePracticeKeys,
  updateOfficePractice,
} from "@/services/office-practice";
import {
  OFFICE_PRACTICE_TYPES,
  PROFESSIONAL_RELATIONSHIP_TYPES,
  PROFESSION_TYPES,
  type ProfessionalAppointment,
} from "@/types/office-practice";

export function OfficePracticeSettings() {
  const t = useTranslations("officePractice");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();
  const [adding, setAdding] = useState(false);
  const [ending, setEnding] = useState<ProfessionalAppointment | null>(null);

  const query = useQuery({
    queryKey: officePracticeKeys.detail,
    queryFn: getOfficePractice,
    retry: false,
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: officePracticeKeys.all });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        <Skeleton className="h-52 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (query.isError || !query.data) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toOfficePracticeErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  const office = query.data;

  return (
    <div className="flex max-w-5xl flex-col gap-6">
      <PracticeIdentityForm office={office} />

      <Card>
        <CardHeader
          title={t("professionalsTitle")}
          description={t("professionalsDescription")}
          action={
            office.can_update && !adding ? (
              <Button size="sm" variant="outline" onClick={() => setAdding(true)}>
                {t("addProfessional")}
              </Button>
            ) : undefined
          }
        />

        {office.professionals.length === 0 ? (
          <p className="text-muted-foreground text-sm">{t("professionalsEmpty")}</p>
        ) : (
          <ul className="flex flex-col gap-3">
            {office.professionals.map((appointment) => (
              <ProfessionalRow
                key={appointment.id}
                appointment={appointment}
                canUpdate={office.can_update}
                onEnd={() => setEnding(appointment)}
              />
            ))}
          </ul>
        )}

        {office.can_update && adding ? (
          <AddProfessionalForm
            onCancel={() => setAdding(false)}
            onAdded={() => {
              setAdding(false);
              void refresh();
            }}
          />
        ) : null}
      </Card>

      <EndAppointmentDialog
        appointment={ending}
        onClose={() => setEnding(null)}
        onEnded={() => {
          setEnding(null);
          void refresh();
        }}
      />
    </div>
  );
}

function PracticeIdentityForm({
  office,
}: {
  office: Awaited<ReturnType<typeof getOfficePractice>>;
}) {
  const t = useTranslations("officePractice");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();
  const [saved, setSaved] = useState(false);
  const schema = z.object({
    practice_type: z.enum(OFFICE_PRACTICE_TYPES),
    jurisdiction: z.string().trim().min(1, t("validation.jurisdictionRequired")).max(255),
  });
  type Values = z.infer<typeof schema>;
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      practice_type: office.practice_type ?? "PPAT",
      jurisdiction: office.jurisdiction ?? "",
    },
  });
  const { reset } = form;

  useEffect(() => {
    reset({
      practice_type: office.practice_type ?? "PPAT",
      jurisdiction: office.jurisdiction ?? "",
    });
  }, [office.jurisdiction, office.practice_type, reset]);

  const mutation = useMutation({
    mutationFn: updateOfficePractice,
    onSuccess: async (updated) => {
      setSaved(true);
      queryClient.setQueryData(officePracticeKeys.detail, updated);
      await queryClient.invalidateQueries({ queryKey: authQueryKeys.me });
    },
    onError: (error: unknown) =>
      form.setError("root", { message: t(`errors.${toOfficePracticeErrorKey(error)}`) }),
  });

  return (
    <Card>
      <CardHeader title={t("identityTitle")} description={t("identityDescription")} />
      <dl className="grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt className="font-medium">{t("officeName")}</dt>
          <dd className="text-muted-foreground">{office.name}</dd>
        </div>
        <div>
          <dt className="font-medium">{t("officeCode")}</dt>
          <dd className="text-muted-foreground">{office.code}</dd>
        </div>
      </dl>

      {office.can_update ? (
        <form
          className="flex max-w-xl flex-col gap-4"
          noValidate
          onSubmit={form.handleSubmit((values) => {
            setSaved(false);
            form.clearErrors("root");
            mutation.mutate(values);
          })}
        >
          {form.formState.errors.root ? (
            <InlineAlert>{form.formState.errors.root.message}</InlineAlert>
          ) : null}
          {saved && !form.formState.isDirty ? (
            <InlineAlert tone="success">{t("identitySaved")}</InlineAlert>
          ) : null}

          <div className="flex flex-col gap-2">
            <Label htmlFor="practice-type">{t("practiceType")}</Label>
            <Select id="practice-type" {...form.register("practice_type")}>
              {OFFICE_PRACTICE_TYPES.map((type) => (
                <option key={type} value={type}>
                  {t(`practiceTypes.${type}`)}
                </option>
              ))}
            </Select>
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="practice-jurisdiction">{t("jurisdiction")}</Label>
            <Input
              id="practice-jurisdiction"
              aria-invalid={form.formState.errors.jurisdiction ? true : undefined}
              {...form.register("jurisdiction")}
            />
            {form.formState.errors.jurisdiction ? (
              <p className="text-destructive text-sm">
                {form.formState.errors.jurisdiction.message}
              </p>
            ) : null}
          </div>
          <FormActions>
            <Button type="submit" disabled={mutation.isPending || !form.formState.isDirty}>
              {mutation.isPending ? tActions("saving") : tActions("save")}
            </Button>
          </FormActions>
        </form>
      ) : (
        <dl className="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt className="font-medium">{t("practiceType")}</dt>
            <dd className="text-muted-foreground">
              {office.practice_type ? t(`practiceTypes.${office.practice_type}`) : "—"}
            </dd>
          </div>
          <div>
            <dt className="font-medium">{t("jurisdiction")}</dt>
            <dd className="text-muted-foreground">{office.jurisdiction ?? "—"}</dd>
          </div>
        </dl>
      )}
    </Card>
  );
}

function ProfessionalRow({
  appointment,
  canUpdate,
  onEnd,
}: {
  appointment: ProfessionalAppointment;
  canUpdate: boolean;
  onEnd: () => void;
}) {
  const t = useTranslations("officePractice");
  const details = [
    appointment.registration_number &&
      `${t("registrationNumber")}: ${appointment.registration_number}`,
    appointment.professional_office_name,
    appointment.jurisdiction,
  ].filter(Boolean);

  return (
    <li className="border-border flex flex-wrap items-start justify-between gap-3 rounded-md border p-3">
      <div className="flex min-w-0 flex-col gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-medium break-words">
            {appointment.individual.display_name ?? t("unknownProfessional")}
          </span>
          <Badge tone={appointment.is_active ? "primarySubtle" : "muted"}>
            {appointment.is_active ? t("active") : t("ended")}
          </Badge>
          {appointment.individual.is_archived ? <Badge>{t("archivedPerson")}</Badge> : null}
        </div>
        <span className="text-sm">
          {t(`professionTypes.${appointment.profession_type}`)} ·{" "}
          {t(`relationshipTypes.${appointment.relationship_type}`)}
        </span>
        {details.length > 0 ? (
          <span className="text-muted-foreground text-sm break-words">{details.join(" · ")}</span>
        ) : null}
        <span className="text-muted-foreground text-sm">
          {t("appointedAt")}: {appointment.appointed_at ?? "—"} · {t("endedAt")}:{" "}
          {appointment.ended_at ?? t("current")}
        </span>
      </div>
      {canUpdate && appointment.is_active ? (
        <Button size="sm" variant="outline" onClick={onEnd}>
          {t("endAppointment")}
        </Button>
      ) : null}
    </li>
  );
}

function AddProfessionalForm({ onCancel, onAdded }: { onCancel: () => void; onAdded: () => void }) {
  const t = useTranslations("officePractice");
  const tActions = useTranslations("actions");
  const options = useQuery({
    queryKey: officePracticeKeys.options,
    queryFn: getOfficePracticeOptions,
    retry: false,
  });
  const schema = z.object({
    individual_id: z.string().min(1, t("validation.personRequired")),
    profession_type: z.enum(PROFESSION_TYPES),
    relationship_type: z.enum(PROFESSIONAL_RELATIONSHIP_TYPES),
    registration_number: z.string().trim().max(100),
    appointed_at: z.string(),
    professional_office_name: z.string().trim().max(255),
    office_city: z.string().trim().max(255),
    province: z.string().trim().max(255),
    jurisdiction: z.string().trim().max(255),
  });
  type Values = z.infer<typeof schema>;
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      individual_id: "",
      profession_type: "PPAT",
      relationship_type: "INTERNAL",
      registration_number: "",
      appointed_at: "",
      professional_office_name: "",
      office_city: "",
      province: "",
      jurisdiction: "",
    },
  });
  const mutation = useMutation({
    mutationFn: (values: Values) =>
      addProfessionalAppointment({
        individual_id: values.individual_id,
        profession_type: values.profession_type,
        relationship_type: values.relationship_type,
        ...optionalFields(values),
      }),
    onSuccess: onAdded,
    onError: (error: unknown) =>
      form.setError("root", { message: t(`errors.${toOfficePracticeErrorKey(error)}`) }),
  });

  return (
    <form
      className="border-border flex max-w-2xl flex-col gap-4 rounded-md border p-4"
      noValidate
      onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
    >
      <h3 className="font-medium">{t("addProfessionalTitle")}</h3>
      {form.formState.errors.root ? (
        <InlineAlert>{form.formState.errors.root.message}</InlineAlert>
      ) : null}
      {options.isError ? (
        <InlineAlert>{t(`errors.${toOfficePracticeErrorKey(options.error)}`)}</InlineAlert>
      ) : null}

      <div className="flex flex-col gap-2">
        <Label htmlFor="professional-person">{t("person")}</Label>
        <Select id="professional-person" {...form.register("individual_id")}>
          <option value="">{options.isPending ? t("loadingPeople") : t("selectPerson")}</option>
          {(options.data?.individuals ?? []).map((person) => (
            <option key={person.id} value={person.id}>
              {person.display_name ?? person.id}
            </option>
          ))}
        </Select>
        {options.data?.individuals.length === 0 ? (
          <p className="text-muted-foreground text-sm">{t("noPeople")}</p>
        ) : null}
        {form.formState.errors.individual_id ? (
          <p className="text-destructive text-sm">{form.formState.errors.individual_id.message}</p>
        ) : null}
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <SelectField
          id="professional-profession"
          label={t("professionType")}
          register={form.register("profession_type")}
        >
          {(options.data?.profession_types ?? PROFESSION_TYPES).map((type) => (
            <option key={type} value={type}>
              {t(`professionTypes.${type}`)}
            </option>
          ))}
        </SelectField>
        <SelectField
          id="professional-relationship"
          label={t("relationshipType")}
          register={form.register("relationship_type")}
        >
          {(options.data?.relationship_types ?? PROFESSIONAL_RELATIONSHIP_TYPES).map((type) => (
            <option key={type} value={type}>
              {t(`relationshipTypes.${type}`)}
            </option>
          ))}
        </SelectField>
        <InputField
          id="professional-registration"
          label={t("registrationNumber")}
          type="text"
          register={form.register("registration_number")}
        />
        <InputField
          id="professional-appointed"
          label={t("appointedAt")}
          type="date"
          register={form.register("appointed_at")}
        />
        <InputField
          id="professional-office"
          label={t("professionalOfficeName")}
          type="text"
          register={form.register("professional_office_name")}
        />
        <InputField
          id="professional-city"
          label={t("officeCity")}
          type="text"
          register={form.register("office_city")}
        />
        <InputField
          id="professional-province"
          label={t("province")}
          type="text"
          register={form.register("province")}
        />
        <InputField
          id="professional-jurisdiction"
          label={t("professionalJurisdiction")}
          type="text"
          register={form.register("jurisdiction")}
        />
      </div>

      <FormActions>
        <Button type="button" variant="outline" onClick={onCancel}>
          {tActions("cancel")}
        </Button>
        <Button type="submit" disabled={mutation.isPending || options.isPending || options.isError}>
          {mutation.isPending ? tActions("saving") : t("addProfessionalConfirm")}
        </Button>
      </FormActions>
    </form>
  );
}

function EndAppointmentDialog({
  appointment,
  onClose,
  onEnded,
}: {
  appointment: ProfessionalAppointment | null;
  onClose: () => void;
  onEnded: () => void;
}) {
  const t = useTranslations("officePractice");
  const tActions = useTranslations("actions");
  const [endedAt, setEndedAt] = useState("");
  const mutation = useMutation({
    mutationFn: () => endProfessionalAppointment(appointment?.id ?? "", endedAt),
    onSuccess: () => {
      setEndedAt("");
      onEnded();
    },
  });
  const close = () => {
    setEndedAt("");
    mutation.reset();
    onClose();
  };

  return (
    <Dialog
      open={appointment !== null}
      onOpenChange={(open) => {
        if (!open) {
          close();
        }
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("endTitle")}</DialogTitle>
          <DialogDescription>{t("endDescription")}</DialogDescription>
        </DialogHeader>
        {mutation.isError ? (
          <InlineAlert>{t(`errors.${toOfficePracticeErrorKey(mutation.error)}`)}</InlineAlert>
        ) : null}
        <div className="flex flex-col gap-2">
          <Label htmlFor="appointment-ended-at">{t("endedAt")}</Label>
          <Input
            id="appointment-ended-at"
            type="date"
            value={endedAt}
            onChange={(event) => setEndedAt(event.target.value)}
          />
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={close}>
            {tActions("cancel")}
          </Button>
          <Button disabled={mutation.isPending || endedAt === ""} onClick={() => mutation.mutate()}>
            {mutation.isPending ? tActions("saving") : t("endConfirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function optionalFields(values: Record<string, string>) {
  return Object.fromEntries(
    Object.entries(values)
      .filter(
        ([key, value]) =>
          !["individual_id", "profession_type", "relationship_type"].includes(key) && value !== "",
      )
      .map(([key, value]) => [key, value.trim()]),
  );
}

function InputField({
  id,
  label,
  type,
  register,
}: {
  id: string;
  label: string;
  type: string;
  register: React.ComponentProps<typeof Input>;
}) {
  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input id={id} type={type} {...register} />
    </div>
  );
}

function SelectField({
  id,
  label,
  register,
  children,
}: {
  id: string;
  label: string;
  register: React.ComponentProps<typeof Select>;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Select id={id} {...register}>
        {children}
      </Select>
    </div>
  );
}
