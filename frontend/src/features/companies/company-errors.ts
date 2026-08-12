import { AxiosError } from "axios";

/**
 * Translation keys under the `companies.errors` namespace.
 */
export type CompanyErrorKey =
  "forbidden" | "notFound" | "validation" | "conflict" | "tooManyAttempts" | "network" | "server";

/**
 * Map a failed Company request onto a message the interface can show.
 *
 * Only the HTTP status is read. The response body never reaches the user, so a
 * Laravel exception or internal payload cannot leak through this path (CLAUDE.md
 * sections 32 and 48) — which matters more here than elsewhere, because a failed
 * identity request is one whose error text is most likely to mention something
 * sensitive.
 *
 * `403` and `404` are both ordinary outcomes rather than faults: Party access
 * depends on a Data Scope the browser's permission list cannot express (O-026),
 * and an Individual id, an archived record, or another Office's row all answer
 * one of the two by design.
 *
 * `429` gets its own message because sensitive reveal is rate limited, and
 * "something went wrong" would be a poor description of "you have looked at a
 * lot of identity data in one minute".
 */
export function toCompanyErrorKey(error: unknown): CompanyErrorKey {
  if (!(error instanceof AxiosError)) {
    return "server";
  }

  if (!error.response) {
    return "network";
  }

  switch (error.response.status) {
    case 403:
      return "forbidden";
    case 404:
      return "notFound";
    case 409:
      // A relationship that has already ended. Its own message, because
      // "something went wrong" describes a state that is simply already true.
      return "conflict";
    case 422:
      return "validation";
    case 429:
      return "tooManyAttempts";
    default:
      return "server";
  }
}
