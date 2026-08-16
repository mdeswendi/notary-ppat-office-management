import type { PartyType } from "@/types/party";

/**
 * Where a Party row actually goes.
 *
 * **There is no generic Party detail route, and this function is why there does
 * not need to be.** A Party is only ever an Individual or a Company, each with
 * its own detail surface, its own permissions, and its own lifecycle; a shared
 * page would have to render one of the two anyway, under a permission model that
 * matched neither (D-078).
 *
 * Returns null for an unrecognized type rather than guessing a path — a row the
 * interface cannot place is rendered as plain text, not as a link into nowhere.
 *
 * The return type is left to inference so each branch keeps its literal path
 * shape, which is what lets the locale-aware `Link` typecheck the destination.
 */
export function partyDetailHref(partyType: PartyType | null, id: string) {
  switch (partyType) {
    case "INDIVIDUAL":
      return `/parties/individuals/${id}` as const;
    case "COMPANY":
      return `/parties/companies/${id}` as const;
    default:
      return null;
  }
}
