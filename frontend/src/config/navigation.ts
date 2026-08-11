import {
  Contact,
  KeyRound,
  LayoutDashboard,
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
 * Both must hold. Since bootstrap gives `SUPER_ADMIN` all 171 canonical
 * permissions (D-057), permission alone would light up navigation for every
 * future module — Projects, Notary, PPAT, Billing — and send an administrator to
 * routes that do not exist. Registering a permission is not shipping a feature
 * (D-064).
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
    key: "parties",
    translationKey: "parties",
    icon: Contact,
    implemented: true,
    children: [
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
      // Companies deliberately absent: the route does not exist until M2.3, and
      // registering a permission is not shipping a feature (D-064).
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
  if (!item.requiredPermission) {
    return true;
  }

  return item.requiredScope
    ? canWithScope(user, item.requiredPermission, item.requiredScope)
    : can(user, item.requiredPermission);
}
