import { navigationItems, visibleNavigation, type NavigationItem } from "@/config/navigation";
import type { CurrentUser } from "@/types/auth";

/**
 * The branch O-032 said "a four-line test would pin", and nothing did.
 *
 * `visibleNavigation` decides what a user is offered, from two independent
 * questions — does the route exist (`implemented`), and may this account use it
 * (`requiredPermission` / `anyPermissions` / `requiredScope`). Conflating them
 * is the mistake `navigation.ts` exists to prevent, and until now only typecheck
 * and a running API said it did not happen.
 */
function user(permissions: string[], scopes: CurrentUser["permission_scopes"] = {}): CurrentUser {
  return {
    id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    name: "Uji",
    email: "uji@example.test",
    preferred_locale: "id",
    roles: [],
    permissions,
    permission_scopes: scopes,
  };
}

/**
 * Every key in a tree, parents and children alike.
 *
 * Takes a `ReadonlyArray` so it accepts both `navigationItems` — which is
 * readonly precisely so nothing mutates the shared configuration — and the
 * mutable array `visibleNavigation` returns.
 */
function keysOf(items: ReadonlyArray<NavigationItem>): string[] {
  return items.flatMap((item) => [item.key, ...keysOf(item.children ?? [])]);
}

describe("visibleNavigation", () => {
  it("shows an unrestricted implemented entry to anyone signed in", () => {
    // Dashboard carries no permission deliberately: inventing a gate for the
    // landing page would lock people out of the only destination they have.
    expect(keysOf(visibleNavigation(user([])))).toContain("dashboard");
  });

  it("shows nothing permission-gated to a user with no permissions", () => {
    const visible = keysOf(visibleNavigation(user([])));

    expect(visible).not.toContain("projects.list");
    expect(visible).not.toContain("notary.matters");
    expect(visible).not.toContain("settings.roles");
  });

  it("hides an unimplemented entry even from someone who holds its permission", () => {
    // The rule D-064 exists for: bootstrap gives SUPER_ADMIN every canonical
    // permission, so permission alone would light up routes that do not exist.
    const items: NavigationItem[] = [
      {
        key: "future",
        translationKey: "future",
        href: "/future",
        icon: navigationItems[0].icon,
        implemented: false,
        requiredPermission: "projects.view",
      },
    ];

    expect(visibleNavigation(user(["projects.view"]), items)).toEqual([]);
  });

  it("hides a parent whose every child was filtered away", () => {
    // An empty Settings menu is worse than no Settings menu: it advertises
    // something and then does nothing.
    const visible = keysOf(visibleNavigation(user([])));

    expect(visible).not.toContain("settings");
    expect(visible).not.toContain("projects");
  });

  it("shows a parent as soon as one child survives", () => {
    const visible = keysOf(visibleNavigation(user(["users.view"])));

    expect(visible).toContain("settings");
    expect(visible).toContain("settings.users");
    expect(visible).not.toContain("settings.roles");
  });

  it("does not mutate the source configuration while filtering children", () => {
    // `visibleNavigation` rebuilds parents with a narrowed `children` array; if
    // it narrowed in place, the second render of a session would show fewer
    // entries than the first.
    const before = keysOf(navigationItems);

    visibleNavigation(user(["users.view"]));
    visibleNavigation(user([]));

    expect(keysOf(navigationItems)).toEqual(before);
  });
});

describe("visibleNavigation — requiredScope", () => {
  it("requires the exact scope a deployment-global destination declares", () => {
    // Role definitions have no office or owner for a narrower predicate to
    // match, so the entry demands `ALL` specifically (D-044).
    const office = user(["roles.view"], { "roles.view": ["OFFICE"] });
    const all = user(["roles.view"], { "roles.view": ["ALL"] });

    expect(keysOf(visibleNavigation(office))).not.toContain("settings.roles");
    expect(keysOf(visibleNavigation(all))).toContain("settings.roles");
  });
});

describe("visibleNavigation — anyPermissions", () => {
  /**
   * The three cases O-032 named. The Party Directory shows Individuals to a
   * holder of `parties.view` and Companies to a holder of `companies.view`, so
   * either alone is enough — requiring both would hide a working page, and
   * inventing a `parties.directory.view` would let an administrator grant the
   * page without granting sight of anything on it.
   */
  it("shows the directory to a holder of parties.view alone", () => {
    expect(keysOf(visibleNavigation(user(["parties.view"])))).toContain("parties.directory");
  });

  it("shows the directory to a holder of companies.view alone", () => {
    expect(keysOf(visibleNavigation(user(["companies.view"])))).toContain("parties.directory");
  });

  it("hides the directory from a holder of neither", () => {
    expect(keysOf(visibleNavigation(user(["projects.view"])))).not.toContain("parties.directory");
  });

  it("still applies requiredPermission when both fields are set", () => {
    // Both must hold, so adding `anyPermissions` to an entry can never widen
    // what `requiredPermission` already narrowed.
    const items: NavigationItem[] = [
      {
        key: "both",
        translationKey: "both",
        href: "/both",
        icon: navigationItems[0].icon,
        implemented: true,
        requiredPermission: "projects.view",
        anyPermissions: ["parties.view", "companies.view"],
      },
    ];

    expect(visibleNavigation(user(["parties.view"]), items)).toEqual([]);
    expect(visibleNavigation(user(["projects.view"]), items)).toEqual([]);
    expect(visibleNavigation(user(["projects.view", "parties.view"]), items)).toHaveLength(1);
  });
});

describe("visibleNavigation — the M4 domain groups", () => {
  it("gates each domain on its own capability, never a shared one", () => {
    // An account may hold Notary Matter access and not PPAT, or the reverse:
    // the two are independent codes (D-101), and the section 5 role matrix
    // deliberately gives Notary Staff and PPAT Staff opposite reach.
    const notary = keysOf(visibleNavigation(user(["notary.matters.view"])));
    const ppat = keysOf(visibleNavigation(user(["ppat.matters.view"])));

    expect(notary).toContain("notary.matters");
    expect(notary).not.toContain("ppat.matters");

    expect(ppat).toContain("ppat.matters");
    expect(ppat).not.toContain("notary.matters");
  });

  it("carries Matters and nothing else in either domain group", () => {
    // Deeds, Minuta, Warkah and registers belong to M6 and M7 and are absent
    // rather than shown dark: a group whose every child is unreachable is a
    // promise the product does not keep.
    const both = visibleNavigation(user(["notary.matters.view", "ppat.matters.view"]));
    const groups = both.filter((item) => item.key === "notary" || item.key === "ppat");

    expect(groups).toHaveLength(2);

    for (const group of groups) {
      expect(group.children).toHaveLength(1);
    }
  });
});

describe("visibleNavigation — the M5 Documents entry", () => {
  it("shows Documents only to an account holding documents.view", () => {
    // The nine `documents.*` codes have been canonical since the catalogue was
    // transcribed, and the entry stayed absent through five milestones because
    // registering a permission is not shipping a feature (D-064). It appears at
    // M5.2, when the routes landed.
    expect(keysOf(visibleNavigation(user(["documents.view"])))).toContain("documents");
    expect(keysOf(visibleNavigation(user(["documents.upload"])))).not.toContain("documents");
    expect(keysOf(visibleNavigation(user([])))).not.toContain("documents");
  });

  it("carries no children, because there is one document surface", () => {
    // Unlike Notary and PPAT, `documents.*` has no domain split — so there is
    // nothing for children to separate, and a group with one child would be a
    // level of nesting that means nothing.
    const entry = navigationItems.find((item) => item.key === "documents");

    expect(entry?.href).toBe("/documents");
    expect(entry?.children).toBeUndefined();
  });
});

describe("navigation configuration", () => {
  it("gives every entry a stable key and a translation key", () => {
    // Keys are never translated and hrefs are locale-relative; a hardcoded
    // locale segment here would break the other locale silently.
    for (const item of [...navigationItems, ...navigationItems.flatMap((i) => i.children ?? [])]) {
      expect(item.key).not.toBe("");
      expect(item.translationKey).not.toBe("");

      if (item.href !== undefined) {
        expect(item.href.startsWith("/")).toBe(true);
        expect(item.href.startsWith("/id/")).toBe(false);
        expect(item.href.startsWith("/en/")).toBe(false);
      }
    }
  });

  it("pairs requiredScope only with requiredPermission", () => {
    // An exact scope means one specific capability; pairing it with a set of
    // alternatives would be ambiguous about which capability the scope belonged
    // to, which is why `navigation.ts` says the two do not combine.
    for (const item of [...navigationItems, ...navigationItems.flatMap((i) => i.children ?? [])]) {
      if (item.requiredScope !== undefined) {
        expect(item.requiredPermission).toBeDefined();
        expect(item.anyPermissions).toBeUndefined();
      }
    }
  });
});
