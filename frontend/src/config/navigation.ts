import {
  ArchiveRestore,
  BookUser,
  Briefcase,
  Building2,
  Contact,
  FileText,
  FolderKanban,
  KeyRound,
  Landmark,
  LayoutDashboard,
  ListChecks,
  Scale,
  Settings,
  UserRound,
  Users,
  type LucideIcon,
} from "lucide-react";

import { can, canWithScope } from "@/lib/permissions/can";
import type { CurrentUser, DataScope } from "@/types/auth";

/**
 * Sidebar navigation, as data rather than JSX branching (CLAUDE.md section 47).
 *
 * Two independent questions decide whether an entry appears, and conflating them
 * is the mistake this file exists to prevent:
 *
 *   `implemented`         does the route exist and is it meant to be reachable?
 *   `requiredPermission`  may this account use it?
 *
 * Both must hold. Since bootstrap gives `SUPER_ADMIN` every canonical
 * permission (D-057), permission alone would light up navigation for every
 * future module — Billing, Tasks — and send an administrator to routes that do
 * not exist. Registering a permission is not shipping a feature (D-064).
 *
 * **Documents were exactly that case for five milestones.** The nine
 * `documents.*` codes have been canonical since the catalogue was transcribed;
 * the entry appears at M5.2, when the routes landed — not at M5.1, which shipped
 * the schema, private storage and the Policy and no surface at all.
 *
 * **The Notary and PPAT groups carry only Matters** *(M4.4)*. Deeds, Minuta,
 * Warkah, registers and protocols belong to M6 and M7 and are absent rather than
 * shown dark, because a group whose every child is unreachable is a promise the
 * product does not keep.
 *
 * A future module's entry may be added here with `implemented: false` and stay
 * dark until its route lands.
 */
export type NavigationItem = {
  /** Stable identifier. Never translated. */
  key: string;
  /** Key into the `navigation` message namespace. */
  translationKey: string;
  /** Locale-relative path. The active locale segment is added by next-intl. */
  href?: string;
  icon: LucideIcon;
  /**
   * Whether the destination actually exists yet. An entry that is `false` never
   * renders, whatever the account may do.
   */
  implemented: boolean;
  /** Canonical permission code required to see this entry. */
  requiredPermission?: string;
  /**
   * An exact Data Scope the permission must include — used by deployment-global
   * destinations. Exact membership, never a comparison.
   */
  requiredScope?: DataScope;
  /**
   * Canonical permission codes of which **any one** is enough.
   *
   * For destinations composed from more than one capability rather than gated by
   * a single one. The Party Directory is the first: it shows Individuals to a
   * holder of `parties.view` and Companies to a holder of `companies.view`, and
   * a person holding either has something real to open. Requiring both would
   * hide a working page; requiring an invented `parties.directory.view` would let
   * an administrator grant the directory without granting sight of anything in
   * it, or withhold it from somebody who can already open every record it lists.
   *
   * Mutually exclusive with `requiredPermission` in practice — an entry that
   * needs one specific capability should say so with that field. When both are
   * set, both must hold, so neither can quietly widen the other.
   *
   * `requiredScope` deliberately does **not** combine with this: an exact scope
   * means one specific capability, and pairing it with a set of alternatives
   * would be ambiguous about which capability the scope belonged to.
   */
  anyPermissions?: readonly string[];
  children?: NavigationItem[];
};

export const navigationItems: ReadonlyArray<NavigationItem> = [
  {
    key: "dashboard",
    translationKey: "dashboard",
    // The locale root redirects here, so link to the real route: linking to
    // "/" would cost a redirect hop and the active state would never match.
    href: "/dashboard",
    icon: LayoutDashboard,
    implemented: true,
    // No permission: no canonical document defines one for the Dashboard, and
    // inventing a gate for the landing page would lock people out of the only
    // destination they have.
  },
  {
    key: "projects",
    translationKey: "projects",
    icon: FolderKanban,
    // Added at M3.3, when the product surface landed — not at M3.1 when the
    // schema did, and not at M3.2 when the allocator did (D-064).
    implemented: true,
    children: [
      {
        key: "projects.list",
        translationKey: "projectsList",
        href: "/projects",
        icon: FolderKanban,
        implemented: true,
        // Any effective Project scope opens the list; the query narrows the rows
        // (D-088). A grant carrying only TEAM reaches nothing, so the backend
        // refuses outright rather than serving a reliably empty page.
        requiredPermission: "projects.view",
      },
      {
        key: "projects.archived",
        translationKey: "projectsArchived",
        href: "/projects/archived",
        icon: ArchiveRestore,
        implemented: true,
        // `projects.restore`, not `projects.view` — the archived surface answers
        // to the capability that can act on it (D-093). Somebody who may restore
        // must be able to reach the page even if they hold nothing else, which is
        // why this is its own entry rather than a control inside the list.
        requiredPermission: "projects.restore",
      },
    ],
  },
  {
    key: "notary",
    translationKey: "notary",
    icon: Scale,
    // Added at M4.4, when the routes landed — not at M4.2 when the schema did,
    // and not at M4.3 when the allocator did (D-064).
    implemented: true,
    children: [
      {
        key: "notary.matters",
        translationKey: "notaryMatters",
        href: "/notary/matters",
        icon: Briefcase,
        implemented: true,
        // The domain's own capability. An account may hold Notary Matter access
        // and not PPAT, or the reverse — the two are independent, so each entry
        // is gated on its own code rather than on a shared one (D-101).
        requiredPermission: "notary.matters.view",
      },
    ],
  },
  {
    key: "ppat",
    translationKey: "ppat",
    icon: Landmark,
    implemented: true,
    children: [
      {
        key: "ppat.matters",
        translationKey: "ppatMatters",
        href: "/ppat/matters",
        icon: Briefcase,
        implemented: true,
        requiredPermission: "ppat.matters.view",
      },
    ],
  },
  {
    key: "tasks",
    translationKey: "tasks",
    icon: ListChecks,
    // Added at M5.4, when the routes landed. The eight `tasks.*` codes have been
    // canonical since the catalogue was transcribed and this entry stayed absent
    // for every milestone since (D-064).
    implemented: true,
    children: [
      {
        key: "tasks.my",
        translationKey: "tasksMy",
        href: "/tasks/my",
        icon: ListChecks,
        implemented: true,
        // The same capability as the list below: "mine" is a filter, not a
        // separate permission.
        requiredPermission: "tasks.view",
      },
      {
        key: "tasks.all",
        translationKey: "tasksAll",
        href: "/tasks",
        icon: ListChecks,
        implemented: true,
        requiredPermission: "tasks.view",
      },
      {
        key: "tasks.completed",
        translationKey: "tasksCompleted",
        href: "/tasks/completed",
        icon: ListChecks,
        implemented: true,
        requiredPermission: "tasks.view",
      },
    ],
  },
  {
    key: "documents",
    translationKey: "documents",
    href: "/documents",
    icon: FileText,
    // Added at M5.2, when the routes landed — not at M5.1 when the schema,
    // storage and Policy did (D-064). The nine `documents.*` codes have been
    // canonical since the catalogue was transcribed and this entry stayed absent
    // for every one of those milestones.
    implemented: true,
    // A single top-level entry rather than a group, because there is one surface:
    // `documents.*` has no Notary/PPAT split, so there is nothing for children to
    // separate.
    requiredPermission: "documents.view",
  },
  {
    key: "parties",
    translationKey: "parties",
    icon: Contact,
    implemented: true,
    children: [
      {
        key: "parties.directory",
        translationKey: "partiesDirectory",
        href: "/parties",
        icon: BookUser,
        // Added at M2.5, when the route landed — not when the backend endpoint
        // did (D-064).
        implemented: true,
        // Either subtype capability is enough, because the directory shows
        // whichever of the two the caller can reach and the backend composes it
        // that way. No `parties.directory.view` exists and none should: it would
        // be a permission for a page rather than for the records on it.
        //
        // The two scopes stay independent all the way through — nothing here
        // unions or ranks them, and the page says so rather than implying one
        // scope governs every row.
        anyPermissions: ["parties.view", "companies.view"],
      },
      {
        key: "parties.individuals",
        translationKey: "partiesIndividuals",
        href: "/parties/individuals",
        icon: UserRound,
        implemented: true,
        // Any effective Party scope opens the list; the query narrows the rows
        // (D-080). OWN, ASSIGNED, and TEAM reach nothing, so the backend refuses
        // outright rather than serving a reliably empty page.
        requiredPermission: "parties.view",
      },
      {
        key: "parties.companies",
        translationKey: "partiesCompanies",
        href: "/parties/companies",
        icon: Building2,
        // Added at M2.3, when the route landed — not when the permission was
        // registered (D-064). The entry and the destination arrive together.
        implemented: true,
        // `companies.view`, not `parties.view`: Company lifecycle is its own
        // capability, and one does not imply the other. Any effective Company
        // scope opens the list; the query narrows the rows (D-080). OWN,
        // ASSIGNED, and TEAM reach nothing, so the backend refuses outright
        // rather than serving a reliably empty page.
        requiredPermission: "companies.view",
      },
    ],
  },
  {
    key: "settings",
    translationKey: "settings",
    icon: Settings,
    implemented: true,
    children: [
      {
        key: "settings.users",
        translationKey: "settingsUsers",
        href: "/settings/users",
        icon: Users,
        implemented: true,
        // Any effective scope is enough to open the list: M1.5 supports OWN,
        // OFFICE, and ALL, and the query narrows the rows accordingly (D-049).
        requiredPermission: "users.view",
      },
      {
        key: "settings.roles",
        translationKey: "settingsRoles",
        href: "/settings/roles",
        icon: KeyRound,
        implemented: true,
        // Role definitions are deployment-global, so ALL specifically (D-044).
        requiredPermission: "roles.view",
        requiredScope: "ALL",
      },
    ],
  },
];

/**
 * The entries this user should actually see.
 *
 * Used by the desktop sidebar and the mobile drawer alike, so the two cannot
 * drift — a destination hidden on one must be hidden on the other.
 *
 * A parent renders only when at least one of its children survives filtering
 * (`02_MENU_AND_PERMISSIONS.md` section 23). An empty Settings menu is worse
 * than no Settings menu: it advertises something and then does nothing.
 *
 * Presentation only. Every destination authorizes again on the server.
 */
export function visibleNavigation(
  user: CurrentUser | null | undefined,
  items: ReadonlyArray<NavigationItem> = navigationItems,
): NavigationItem[] {
  return items.reduce<NavigationItem[]>((visible, item) => {
    if (!item.implemented || !isPermitted(user, item)) {
      return visible;
    }

    if (item.children) {
      const children = visibleNavigation(user, item.children);

      return children.length > 0 ? [...visible, { ...item, children }] : visible;
    }

    return [...visible, item];
  }, []);
}

function isPermitted(user: CurrentUser | null | undefined, item: NavigationItem): boolean {
  // Every stated condition must hold. An entry with neither field is
  // unrestricted; one with both is restricted by both, so adding `anyPermissions`
  // to an entry can never widen what `requiredPermission` already narrowed.
  if (item.anyPermissions && !item.anyPermissions.some((code) => can(user, code))) {
    return false;
  }

  if (!item.requiredPermission) {
    return true;
  }

  return item.requiredScope
    ? canWithScope(user, item.requiredPermission, item.requiredScope)
    : can(user, item.requiredPermission);
}
