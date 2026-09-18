"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CalendarDays, MapPin, Plus } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";

import { InlineAlert } from "@/components/feedback/inline-alert";
import { PageHeader } from "@/components/layout/page-header";
import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { calendarQueryKeys, createCalendarEvent, getCalendarEvents } from "@/services/calendar";
import { dashboardQueryKeys } from "@/services/dashboard";
import { CALENDAR_EVENT_TYPES, type CalendarEventInput } from "@/types/calendar";

export function CalendarPage() {
  const t = useTranslations("calendar");
  const queryClient = useQueryClient();
  const [formOpen, setFormOpen] = useState(false);
  const events = useQuery({ queryKey: calendarQueryKeys.events(), queryFn: getCalendarEvents });
  const mutation = useMutation({
    mutationFn: createCalendarEvent,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: calendarQueryKeys.all() }),
        queryClient.invalidateQueries({ queryKey: dashboardQueryKeys.all() }),
      ]);
      setFormOpen(false);
    },
  });

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t("title")}
        description={t("description")}
        actions={
          <PermissionGuard permission="calendar.create">
            <Button type="button" onClick={() => setFormOpen((open) => !open)}>
              <Plus aria-hidden="true" />
              {t("add")}
            </Button>
          </PermissionGuard>
        }
      />

      {formOpen ? (
        <CalendarEventForm
          pending={mutation.isPending}
          error={mutation.isError ? t("saveError") : undefined}
          onCancel={() => setFormOpen(false)}
          onSubmit={(input) => mutation.mutate(input)}
        />
      ) : null}

      <section className="bg-card rounded-xl border p-5 shadow-sm">
        <h2 className="mb-4 text-lg font-semibold">{t("upcoming")}</h2>
        {events.isPending ? <p className="text-muted-foreground text-sm">{t("loading")}</p> : null}
        {events.isError ? <p className="text-destructive text-sm">{t("loadError")}</p> : null}
        {events.data?.length === 0 ? (
          <div className="flex flex-col items-center gap-3 py-10 text-center">
            <CalendarDays className="text-muted-foreground/60 size-10" aria-hidden="true" />
            <p className="text-muted-foreground text-sm">{t("empty")}</p>
          </div>
        ) : (
          <div className="divide-y">
            {events.data?.map((event) => (
              <article key={event.id} className="flex gap-4 py-4 first:pt-0 last:pb-0">
                <time className="text-muted-foreground w-28 shrink-0 text-sm font-medium tabular-nums">
                  {formatDate(event.starts_at)}
                </time>
                <div className="min-w-0">
                  <h3 className="font-medium">{event.title}</h3>
                  <p className="text-muted-foreground text-sm">{t(`types.${event.event_type}`)}</p>
                  {event.location ? (
                    <p className="text-muted-foreground mt-1 flex items-center gap-1 text-xs">
                      <MapPin className="size-3" aria-hidden="true" /> {event.location}
                    </p>
                  ) : null}
                  {event.description ? (
                    <p className="text-muted-foreground mt-1 text-sm">{event.description}</p>
                  ) : null}
                </div>
              </article>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}

function CalendarEventForm({
  pending,
  error,
  onCancel,
  onSubmit,
}: {
  pending: boolean;
  error?: string;
  onCancel: () => void;
  onSubmit: (input: CalendarEventInput) => void;
}) {
  const t = useTranslations("calendar");
  const [values, setValues] = useState({
    title: "",
    event_type: "APPOINTMENT" as CalendarEventInput["event_type"],
    starts_at: "",
    ends_at: "",
    location: "",
    description: "",
  });
  const update = (key: keyof typeof values, value: string) =>
    setValues((current) => ({ ...current, [key]: value }));

  return (
    <form
      className="bg-card flex flex-col gap-4 rounded-xl border p-5 shadow-sm"
      onSubmit={(event) => {
        event.preventDefault();
        onSubmit({
          ...values,
          ends_at: values.ends_at || null,
          location: values.location || null,
          description: values.description || null,
        });
      }}
    >
      {error ? <InlineAlert>{error}</InlineAlert> : null}
      <h2 className="text-lg font-semibold">{t("formTitle")}</h2>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          label={t("fields.title")}
          value={values.title}
          onChange={(value) => update("title", value)}
          required
        />
        <div className="flex flex-col gap-2">
          <Label htmlFor="event_type">{t("fields.type")}</Label>
          <Select
            id="event_type"
            value={values.event_type}
            onChange={(event) => update("event_type", event.target.value)}
          >
            {CALENDAR_EVENT_TYPES.map((type) => (
              <option key={type} value={type}>
                {t(`types.${type}`)}
              </option>
            ))}
          </Select>
        </div>
        <Field
          label={t("fields.startsAt")}
          type="datetime-local"
          value={values.starts_at}
          onChange={(value) => update("starts_at", value)}
          required
        />
        <Field
          label={t("fields.endsAt")}
          type="datetime-local"
          value={values.ends_at}
          onChange={(value) => update("ends_at", value)}
        />
        <Field
          label={t("fields.location")}
          value={values.location}
          onChange={(value) => update("location", value)}
        />
      </div>
      <div className="flex flex-col gap-2">
        <Label htmlFor="description">{t("fields.description")}</Label>
        <Textarea
          id="description"
          rows={3}
          value={values.description}
          onChange={(event) => update("description", event.target.value)}
        />
      </div>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onCancel}>
          {t("cancel")}
        </Button>
        <Button type="submit" disabled={pending}>
          {pending ? t("saving") : t("save")}
        </Button>
      </div>
    </form>
  );
}

function Field({
  label,
  value,
  onChange,
  type = "text",
  required = false,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  type?: string;
  required?: boolean;
}) {
  const id = label.toLowerCase().replace(/[^a-z0-9]+/g, "-");
  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type={type}
        value={value}
        required={required}
        onChange={(event) => onChange(event.target.value)}
      />
    </div>
  );
}

function formatDate(value: string): string {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(
    new Date(value),
  );
}
