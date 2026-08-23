import type { MatterDomain } from "@/types/matter";

/**
 * The two things a Matter screen needs to know from its domain (M4.4, D-101).
 *
 * Both are derived from the **address the screen lives at**, never from data:
 * `/notary/matters` is a Notary surface and `/ppat/matters` is a PPAT one, which
 * mirrors the backend rule that the route selects the permission namespace. A
 * component is handed its domain by the page under which it is mounted, so no
 * screen ever has to ask a record which surface it belongs to.
 */

/** The locale-relative route root — `Link` adds the locale segment. */
export function matterBasePath(domain: MatterDomain): string {
  return domain === "NOTARY" ? "/notary/matters" : "/ppat/matters";
}

/**
 * The canonical permission code for one Matter capability in this domain.
 *
 * There is no generic `matters.*` namespace and none may be invented — the
 * registry splits the surface, and so does this.
 *
 * Used only for **presentation gates**: `PermissionGuard` decides what is
 * offered, and every endpoint authorizes again. A client that lies to itself
 * about these gains nothing.
 */
export function matterCapability(
  domain: MatterDomain,
  capability: "view" | "create" | "update" | "assign" | "complete" | "cancel",
): string {
  return `${domain === "NOTARY" ? "notary" : "ppat"}.matters.${capability}`;
}
