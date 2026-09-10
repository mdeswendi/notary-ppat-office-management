import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

import { MobileNav } from "@/components/layout/mobile-nav";
import type { CurrentUser } from "@/types/auth";

/**
 * The navigation drawer for viewports below `lg`.
 *
 * These pin the fix for a reported runtime bug: below `lg`, the drawer's own
 * nav list had no scroll container of its own, so a list taller than the
 * sheet left its last destinations unreachable — not clipped, just nowhere a
 * scroll gesture could reach. `SheetContent` is `fixed inset-y-0 h-full`, a
 * genuinely bounded height, so `nav` is what should grow to fill it and
 * scroll — the same `flex-1 overflow-y-auto` pairing `AppSidebar` already
 * uses for the desktop rail.
 *
 * jsdom computes no real layout, so nothing here can assert that content
 * actually overflows or that a scrollbar paints — that part is a static
 * inspection claim, not a test claim. What these tests can and do pin: the
 * classes that produce the scroll behaviour are present on the right
 * element, the full destination list survives to the DOM regardless of its
 * length, the last destination is a real focus target, and the drawer's
 * close control still works.
 */

const user: CurrentUser = {
  id: "01TESTUSER00000000000000000",
  name: "Pengguna Uji",
  email: "uji@example.test",
  preferred_locale: "id",
  office: {
    id: "01OFFICE0000000000000000000",
    code: "TEST-01",
    name: "Kantor Uji",
    organization: { id: "01ORG000000000000000000000", name: "Organisasi Uji" },
  },
  roles: [],
  permissions: ["notary.matters.view", "roles.view"],
  permission_scopes: { "notary.matters.view": ["ALL"], "roles.view": ["ALL"] },
};

async function openDrawer() {
  const events = userEvent.setup();
  render(<MobileNav user={user} />);

  await events.click(screen.getByRole("button", { name: "navigation.openNavigation" }));

  return events;
}

describe("MobileNav", () => {
  it("makes the nav list its own scroll container, not the sheet panel", async () => {
    await openDrawer();

    const nav = await screen.findByRole("navigation", { name: "navigation.mainLabel" });

    // The mechanism the fix relies on, not a spacing choice — this is the
    // pairing that lets `nav` absorb the sheet's bounded height and scroll
    // its own overflow rather than the whole panel growing past the viewport.
    expect(nav).toHaveClass("flex-1");
    expect(nav).toHaveClass("overflow-y-auto");
  });

  it("keeps the first and last navigation destinations in the drawer", async () => {
    await openDrawer();

    // Both are real leaves of the same real `visibleNavigation` filter used
    // by the desktop sidebar — not a hand-built stand-in list — so this
    // fixture's permission set is chosen to keep the very first entry
    // (Dashboard, which needs none) and the very last entry (Roles, gated on
    // `roles.view` at `ALL`) both visible.
    expect(await screen.findByRole("link", { name: "navigation.dashboard" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "navigation.settingsRoles" })).toBeInTheDocument();
  });

  it("lets the last destination receive keyboard focus", async () => {
    await openDrawer();

    const last = await screen.findByRole("link", { name: "navigation.settingsRoles" });
    last.focus();

    expect(last).toHaveFocus();
  });

  it("still closes from its own close control", async () => {
    const events = await openDrawer();

    await events.click(await screen.findByRole("button", { name: "navigation.closeNavigation" }));

    expect(screen.queryByRole("link", { name: "navigation.dashboard" })).not.toBeInTheDocument();
  });
});
