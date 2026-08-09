import { LayoutDashboard, type LucideIcon } from "lucide-react";

/**
 * Sidebar navigation configuration.
 *
 * Menu items live here rather than inside the Sidebar component, per
 * CLAUDE.md section 47 and docs/10_M0_FOUNDATION.md section 37.
 *
 * Only the Dashboard foundation placeholder is listed. The full business
 * navigation in docs/04_UI_DESIGN_SYSTEM.md section 11 is deliberately absent:
 * listing modules that have no route yet would advertise features that do not
 * exist.
 */
export type NavigationItem = {
  /** Stable identifier. Never translated. */
  key: string;
  /** Key into the `navigation` message namespace. */
  translationKey: string;
  /** Locale-relative path. The active locale segment is added by next-intl. */
  href: string;
  icon: LucideIcon;
  /**
   * Declared for the eventual permission-based menu described in
   * CLAUDE.md section 27. Nothing reads it yet — M0.8 owns authorization, and
   * backend policy remains the security boundary regardless.
   */
  requiredPermission?: string;
};

export const navigationItems: ReadonlyArray<NavigationItem> = [
  {
    key: "dashboard",
    translationKey: "dashboard",
    href: "/",
    icon: LayoutDashboard,
  },
];
