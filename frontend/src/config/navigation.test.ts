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
    // Null on purpose: navigation is decided by capability, never by Office, and
    // a fixture carrying one would suggest otherwise.
    office: null,
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

  it("carries the four PPAT destinations and nothing else", () => {
    // **Narrowed four times, never deleted.** It first asserted that *both* domain
    // groups carried exactly one child, which was right until M6.2 gave Notary its
    // Deeds entry; M6.2 narrowed it to PPAT alone; M7.2 gave PPAT its Deeds entry;
    // M7.3 gave it Property; M7.4 gives it Warkah and completes the group.
    //
    // What survives is the claim the test was really making, and it still holds:
    // **taxes, registers and reports have canonical capabilities and no routes**, so
    // they are absent rather than shown dark — a group whose child is unreachable is a
    // promise the product does not keep (D-064). `ppat.taxes.*` does not even have
    // codes (O-040).
    const both = visibleNavigation(user(["notary.matters.view", "ppat.matters.view"]));
    const ppat = both.find((item) => item.key === "ppat");

    // One child here, because this actor holds `ppat.matters.view` and none of the
    // other three gates — all four are independent.
    expect(ppat?.children).toHaveLength(1);

    const capable = visibleNavigation(
      user(["ppat.matters.view", "ppat.deeds.view", "properties.view", "ppat.warkah.view"]),
    );
    const group = capable.find((item) => item.key === "ppat");

    expect(keysOf(group?.children ?? [])).toEqual([
      "ppat.matters",
      "ppat.deeds",
      "ppat.properties",
      "ppat.warkah",
    ]);
  });
});

describe("visibleNavigation — the M6 Notary Deeds entry", () => {
  it("shows Deeds only to an account holding notary.deeds.view", () => {
    // The seven `notary.deeds.*` codes have been canonical since the catalogue was
    // transcribed at M1.2, and the entry stayed absent through six milestones —
    // including M6.1, which built the schema and the Policy. Registering a
    // permission is not shipping a feature (D-064); the entry appears at M6.2, when
    // the routes landed.
    expect(keysOf(visibleNavigation(user(["notary.deeds.view"])))).toContain("notary.deeds");
    expect(keysOf(visibleNavigation(user(["notary.deeds.create"])))).not.toContain("notary.deeds");
    expect(keysOf(visibleNavigation(user([])))).not.toContain("notary.deeds");
  });

  it("gates Deeds and Matters on their own codes, in both directions", () => {
    // Reaching a Matter confers no Deed authority and reaching a Deed confers no
    // Matter authority (D-100, restated one level down at D-120). An account may
    // legitimately hold either alone, and either alone opens the group.
    const matters = keysOf(visibleNavigation(user(["notary.matters.view"])));
    const deeds = keysOf(visibleNavigation(user(["notary.deeds.view"])));

    expect(matters).toContain("notary.matters");
    expect(matters).not.toContain("notary.deeds");

    expect(deeds).toContain("notary.deeds");
    expect(deeds).not.toContain("notary.matters");

    expect(matters).toContain("notary");
    expect(deeds).toContain("notary");
  });

  it("does not carry into the PPAT group", () => {
    // **Narrowed at M7.2, not deleted.** This read "offers no PPAT counterpart" and
    // claimed the catalogue had no `ppat.deeds.view`, which was wrong even then —
    // seven `ppat.deeds.*` codes have been canonical since M1.2, and what was
    // actually missing was the route. M7.2 built it.
    //
    // The claim worth keeping is the one about namespaces: `notary.deeds.view` is a
    // Notary-only code and gates a Notary-only entry. An account holding it and no
    // PPAT deed capability still sees no PPAT Deeds entry.
    const visible = visibleNavigation(user(["notary.deeds.view", "ppat.matters.view"]));
    const ppat = visible.find((item) => item.key === "ppat");

    expect(keysOf(ppat?.children ?? [])).not.toContain("ppat.deeds");
  });

  it("offers no Minuta, register or protocol destination", () => {
    // Minuta is M6.3. Registers and protocol are outside M6 entirely — batch 11 per
    // the ERD, and the catalogue has no `notary.protocol.*` code at all (O-036).
    // An entry for any of them would be a promise the product does not keep.
    const everything = keysOf(
      visibleNavigation(
        user([
          "notary.deeds.view",
          "notary.matters.view",
          "notary.minuta.view",
          "notary.register.view",
        ]),
      ),
    );

    expect(everything).not.toContain("notary.minuta");
    expect(everything).not.toContain("notary.register");
    expect(everything).not.toContain("notary.protocol");
  });
});

describe("visibleNavigation — the M7 PPAT Deeds entry", () => {
  it("shows Deeds only to an account holding ppat.deeds.view", () => {
    // The same sequence the Notary entry followed: the `ppat.deeds.*` codes have been
    // canonical since M1.2 and the entry stayed absent through M7.1, which built the
    // schema and the Policy. Registering a permission is not shipping a feature
    // (D-064); the entry appears at M7.2, when the routes landed.
    expect(keysOf(visibleNavigation(user(["ppat.deeds.view"])))).toContain("ppat.deeds");
    expect(keysOf(visibleNavigation(user(["ppat.deeds.create"])))).not.toContain("ppat.deeds");
    expect(keysOf(visibleNavigation(user([])))).not.toContain("ppat.deeds");
  });

  it("gates Deeds and Matters on their own codes, in both directions", () => {
    // Reaching a Matter confers no Deed authority and reaching a Deed confers no
    // Matter authority (D-100, restated one level down at D-121). An account may
    // legitimately hold either alone, and either alone opens the group.
    const matters = keysOf(visibleNavigation(user(["ppat.matters.view"])));
    const deeds = keysOf(visibleNavigation(user(["ppat.deeds.view"])));

    expect(matters).toContain("ppat.matters");
    expect(matters).not.toContain("ppat.deeds");

    expect(deeds).toContain("ppat.deeds");
    expect(deeds).not.toContain("ppat.matters");

    expect(matters).toContain("ppat");
    expect(deeds).toContain("ppat");
  });

  it("does not carry into the Notary group", () => {
    // The mirror of the Notary test above. `ppat.deeds.view` gates a PPAT-only entry.
    const visible = visibleNavigation(user(["ppat.deeds.view", "notary.matters.view"]));
    const notary = visible.find((item) => item.key === "notary");

    expect(keysOf(notary?.children ?? [])).not.toContain("notary.deeds");
  });

  /**
   * **Narrowed at M7.3 and again at M7.4, never deleted.** This once asserted that
   * Property *and* Warkah were among the destinations D-064 refused, which was true
   * while neither route existed — both the M7.2 and M7.3 briefs asked for placeholders
   * and both were declined. M7.3 built Property and M7.4 built Warkah, so each leaves
   * the list in turn and gains its own tests. **O-044 closes here.**
   *
   * The rest has not expired, and is what the guard now protects: registers, taxes,
   * protocol and reports all have tables or capabilities and no routes, which is
   * precisely the situation D-064 was written for. `ppat.taxes.*` does not even have
   * codes — the finding that shaped M7's scope (O-040).
   *
   * Both built capabilities are handed to the actor here specifically to show that
   * holding them lights up **those two and nothing else**.
   */
  it("offers no register, tax, protocol or report destination", () => {
    const everything = keysOf(
      visibleNavigation(
        user([
          "ppat.deeds.view",
          "ppat.matters.view",
          "properties.view",
          "ppat.warkah.view",
          "ppat.register.view",
          "ppat.reports.view",
        ]),
      ),
    );

    expect(everything).toContain("ppat.properties");
    expect(everything).toContain("ppat.warkah");

    expect(everything).not.toContain("ppat.register");
    expect(everything).not.toContain("ppat.taxes");
    expect(everything).not.toContain("ppat.protocol");
    expect(everything).not.toContain("ppat.reports");
  });
});

describe("visibleNavigation — the M7.4 Warkah entry", () => {
  it("shows Warkah only to an account holding ppat.warkah.view", () => {
    // The last of the four, and the same sequence every entry has followed: the six
    // `ppat.warkah.*` codes have been canonical since M1.2 and the entry stayed absent
    // through M7.1 (three tables), M7.2 and M7.3 — whose briefs both asked for a
    // placeholder. Registering a permission is not shipping a feature (D-064); the
    // entry appears at M7.4, when the routes landed.
    expect(keysOf(visibleNavigation(user(["ppat.warkah.view"])))).toContain("ppat.warkah");
    expect(keysOf(visibleNavigation(user(["ppat.warkah.upload"])))).not.toContain("ppat.warkah");
    expect(keysOf(visibleNavigation(user(["ppat.warkah.verify"])))).not.toContain("ppat.warkah");
    expect(keysOf(visibleNavigation(user([])))).not.toContain("ppat.warkah");
  });

  /**
   * **Reading a deed confers no Warkah reach, and the reverse holds too.**
   *
   * `ppat.warkah.*` is its own family of six codes. An office may let a clerk assemble
   * evidence without letting them read the deed, or the reverse — and the two entries
   * are gated independently so the menu reflects that.
   */
  it("gates Warkah and Deeds on their own codes, in both directions", () => {
    const deeds = keysOf(visibleNavigation(user(["ppat.deeds.view"])));
    const bundles = keysOf(visibleNavigation(user(["ppat.warkah.view"])));

    expect(deeds).toContain("ppat.deeds");
    expect(deeds).not.toContain("ppat.warkah");

    expect(bundles).toContain("ppat.warkah");
    expect(bundles).not.toContain("ppat.deeds");

    expect(deeds).toContain("ppat");
    expect(bundles).toContain("ppat");
  });

  /**
   * **The two unimplemented codes light up nothing.** `ppat.warkah.finalize` and
   * `.archive` are canonical and have no route, because their trigger is open question
   * eight (O-041). An account holding both and nothing else sees no PPAT group at all.
   */
  it("shows nothing for the two warkah codes that have no surface", () => {
    const visible = keysOf(
      visibleNavigation(user(["ppat.warkah.finalize", "ppat.warkah.archive"])),
    );

    expect(visible).not.toContain("ppat");
    expect(visible).not.toContain("ppat.warkah");
  });
});

describe("visibleNavigation — the M7.3 Property entry", () => {
  it("shows Property only to an account holding properties.view", () => {
    // The same sequence every entry has followed: the six `properties.*` codes have
    // been canonical since M1.2 and the entry stayed absent through M7.1, which built
    // eight tables and two Policies, and through M7.2, whose brief asked for a
    // placeholder. Registering a permission is not shipping a feature (D-064); the
    // entry appears at M7.3, when the routes landed.
    expect(keysOf(visibleNavigation(user(["properties.view"])))).toContain("ppat.properties");
    expect(keysOf(visibleNavigation(user(["properties.create"])))).not.toContain("ppat.properties");
    expect(keysOf(visibleNavigation(user(["properties.ownership.view"])))).not.toContain(
      "ppat.properties",
    );
    expect(keysOf(visibleNavigation(user([])))).not.toContain("ppat.properties");
  });

  /**
   * **Gated on `properties.view`, with no `ppat.` prefix.**
   *
   * The entry sits in the PPAT group because `CLAUDE.md` section 16 lists Property
   * among the PPAT-specific concepts — but the canonical capability family is
   * domain-neutral, and there is no `ppat.properties.*` code for a gate to name. A
   * page path is not a permission namespace, and this asserts the gate reads the code
   * the catalogue actually defines.
   */
  it("gates the entry on the domain-neutral code, not an invented ppat one", () => {
    const invented = keysOf(visibleNavigation(user(["ppat.properties.view"])));

    expect(invented).not.toContain("ppat.properties");
    expect(keysOf(visibleNavigation(user(["properties.view"])))).toContain("ppat.properties");
  });

  it("opens the PPAT group for a holder of Property alone", () => {
    // Each child is gated on its own code and any one of them opens the parent — an
    // account may legitimately maintain the office's land records and touch no Matter.
    const visible = keysOf(visibleNavigation(user(["properties.view"])));

    expect(visible).toContain("ppat");
    expect(visible).toContain("ppat.properties");
    expect(visible).not.toContain("ppat.matters");
    expect(visible).not.toContain("ppat.deeds");
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

describe("visibleNavigation — the M5 Tasks group", () => {
  it("gates all three views on the one capability that reads tasks", () => {
    // "Mine" and "Completed" are filters over the same list, not separate
    // capabilities: inventing `tasks.view_mine` would let an administrator grant
    // a page without granting sight of anything on it.
    const visible = keysOf(visibleNavigation(user(["tasks.view"])));

    expect(visible).toContain("tasks");
    expect(visible).toContain("tasks.my");
    expect(visible).toContain("tasks.all");
    expect(visible).toContain("tasks.completed");
  });

  it("hides the whole group from an account that may create but not read", () => {
    // Every child requires `tasks.view`, so the parent collapses with them —
    // a group whose every destination is refused advertises nothing.
    const visible = keysOf(visibleNavigation(user(["tasks.create", "tasks.assign"])));

    expect(visible).not.toContain("tasks");
    expect(visible).not.toContain("tasks.my");
  });

  it("does not gate the group on a scope, because Task reach is a union", () => {
    // Unlike Roles, a Task destination demands no particular scope: `OWN`,
    // `ASSIGNED`, `OFFICE` and `ALL` are separate predicates that union when
    // several are held (D-028), and each of them can reach some Task. A
    // `requiredScope` here would hide a working page from an assignee.
    const own = keysOf(visibleNavigation(user(["tasks.view"], { "tasks.view": ["OWN"] })));
    const assigned = keysOf(
      visibleNavigation(user(["tasks.view"], { "tasks.view": ["ASSIGNED"] })),
    );

    expect(own).toContain("tasks.my");
    expect(assigned).toContain("tasks.my");
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
