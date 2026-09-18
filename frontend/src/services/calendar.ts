import { apiClient } from "@/lib/api/client";
import type { CalendarEvent, CalendarEventInput } from "@/types/calendar";

const ROOT = "/api/v1/calendar/events";

export const calendarQueryKeys = {
  all: () => ["calendar"] as const,
  events: () => ["calendar", "events"] as const,
};

export async function getCalendarEvents(): Promise<CalendarEvent[]> {
  const response = await apiClient.get<{ data: CalendarEvent[] }>(ROOT);
  return response.data.data;
}

export async function createCalendarEvent(input: CalendarEventInput): Promise<CalendarEvent> {
  const response = await apiClient.post<{ data: CalendarEvent }>(ROOT, input);
  return response.data.data;
}
