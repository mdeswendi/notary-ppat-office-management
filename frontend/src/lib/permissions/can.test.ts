import { can, canWithScope, scopesFor } from "@/lib/permissions/can";
import type { CurrentUser } from "@/types/auth";

/**
 * O-032 named these by name: `can` and `canWithScope` were verified by typecheck
 * and by running the real API, never by an executed test.
 *
 * Every assertion here is about **presentation**. The backend is the security
 * boundary (`CLAUDE.md` section 28) and authorizes again on every request; what
 * these pin is that the interface asks the same question the backend will, so a
 * control is offered exactly when following it would work.
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

describe("can", () => {
  it("answers false for an absent user rather than throwing", () => {
    // Every guard runs before `/api/v1/me` resolves. Failing closed is the only
    // safe answer while identity is unknown.
    expect(can(null, "projects.view")).toBe(false);
    expect(can(undefined, "projects.view")).toBe(false);
  });

  it("matches a held permission exactly", () => {
    expect(can(user(["projects.view"]), "projects.view")).toBe(true);
  });

  it("does not match a permission the user lacks", () => {
    expect(can(user(["projects.view"]), "projects.create")).toBe(false);
  });

  it("expands no wildcard and infers no hierarchy", () => {
    // Anything cleverer would be a second authorization engine that could
    // disagree with the real one. `projects.view` is not `projects.*`, and
    // holding a parent namespace grants nothing.
    const actor = user(["projects.view", "notary.matters.view"]);

    expect(can(actor, "projects.*")).toBe(false);
    expect(can(actor, "projects")).toBe(false);
    expect(can(actor, "notary.matters.parties.view")).toBe(false);
  });
});

describe("canWithScope", () => {
  it("requires the exact scope, never a wider one", () => {
    // The rule D-028 turns on: scopes are predicates, not levels. `ALL` does not
    // satisfy a required `OFFICE` by outranking it, and `OFFICE` does not
    // satisfy `ALL` at all.
    const actor = user(["roles.view"], { "roles.view": ["OFFICE"] });

    expect(canWithScope(actor, "roles.view", "OFFICE")).toBe(true);
    expect(canWithScope(actor, "roles.view", "ALL")).toBe(false);
  });

  it("is satisfied when the scope is present among several", () => {
    // `{OFFICE, ALL}` satisfies `ALL` because `ALL` is in the set, not because
    // it outranks `OFFICE`.
    const actor = user(["roles.view"], { "roles.view": ["OFFICE", "ALL"] });

    expect(canWithScope(actor, "roles.view", "ALL")).toBe(true);
    expect(canWithScope(actor, "roles.view", "OFFICE")).toBe(true);
    expect(canWithScope(actor, "roles.view", "OWN")).toBe(false);
  });

  it("refuses a scope for a permission that is not held at all", () => {
    const actor = user([], { "roles.view": ["ALL"] });

    expect(canWithScope(actor, "roles.view", "ALL")).toBe(false);
  });

  it("reports TEAM as itself rather than reinterpreting it", () => {
    // M1.6 forbids assigning TEAM, but legacy data can carry it and the
    // vocabulary is fixed. Silently mapping it to something else would be the
    // interface inventing an authorization rule.
    const actor = user(["projects.view"], { "projects.view": ["TEAM"] });

    expect(canWithScope(actor, "projects.view", "TEAM")).toBe(true);
    expect(canWithScope(actor, "projects.view", "OFFICE")).toBe(false);
  });
});

describe("scopesFor", () => {
  it("returns an empty list rather than undefined when nothing is held", () => {
    expect(scopesFor(null, "projects.view")).toEqual([]);
    expect(scopesFor(user([]), "projects.view")).toEqual([]);
  });

  it("returns an empty list when the permission is held with no scope metadata", () => {
    // A grant carrying no Data Scope grants nothing, and the projection may omit
    // the key entirely. `?? []` is what keeps this from being `undefined` and
    // crashing a caller that iterates.
    expect(scopesFor(user(["projects.view"]), "projects.view")).toEqual([]);
  });
});
