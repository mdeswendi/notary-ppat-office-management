import type { CurrentUser } from "@/types/auth";

/**
 * Names the office according to the practice that is active today.
 *
 * The stored Organization can retain the future combined Notary & PPAT name,
 * while the current PPAT-only interface stays factually correct.
 */
export function resolveOfficeIdentity(office: CurrentUser["office"] | undefined): string | null {
  const identity = office?.organization?.name ?? office?.name;

  if (!identity) {
    return null;
  }

  if (office?.practice_type === "PPAT") {
    return identity.replace(/^Kantor\s+Notaris\s*&\s*PPAT/i, "Kantor PPAT");
  }

  return identity;
}
