import { AxiosError } from "axios";

export type OfficePracticeErrorKey =
  "forbidden" | "notFound" | "validation" | "conflict" | "network" | "server";

export function toOfficePracticeErrorKey(error: unknown): OfficePracticeErrorKey {
  if (!(error instanceof AxiosError)) return "server";
  if (!error.response) return "network";

  switch (error.response.status) {
    case 403:
      return "forbidden";
    case 404:
      return "notFound";
    case 409:
      return "conflict";
    case 422:
      return "validation";
    default:
      return "server";
  }
}
