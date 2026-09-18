export const CALENDAR_EVENT_TYPES = [
  "APPOINTMENT",
  "SIGNING",
  "DEADLINE",
  "REMINDER",
  "INTERNAL_MEETING",
  "OTHER",
] as const;

export type CalendarEventType = (typeof CALENDAR_EVENT_TYPES)[number];

export type CalendarEvent = {
  id: string;
  event_type: CalendarEventType;
  title: string;
  description: string | null;
  starts_at: string;
  ends_at: string | null;
  location: string | null;
  project_id: string | null;
  matter_id: string | null;
};

export type CalendarEventInput = {
  title: string;
  event_type: CalendarEventType;
  starts_at: string;
  ends_at?: string | null;
  location?: string | null;
  description?: string | null;
};
