import en from "../../messages/en.json";
import id from "../../messages/id.json";

/**
 * The top-level `actions` namespace, read from the real catalog files rather
 * than through the `next-intl` mock in `vitest.setup.tsx`.
 *
 * That mock returns `${namespace}.${key}` for every call, which is exactly
 * right for pinning which key a component reached for — and exactly wrong for
 * catching a key that is missing from the catalog itself, since the mock never
 * fails. `MatterDetail`, `PropertyDetail`, and `DocumentDetail` all called
 * `tActions("edit")` for months while `actions.edit` was absent from both
 * locale files; every component test using the mock kept passing, and the
 * gap only surfaced as `MISSING_MESSAGE` in a real browser. This file reads
 * the JSON directly so that class of gap fails here instead.
 */
describe("actions message catalog", () => {
  it("defines edit for the Indonesian locale", () => {
    expect(id.actions.edit).toBe("Ubah");
  });

  it("defines edit for the English locale", () => {
    expect(en.actions.edit).toBe("Edit");
  });

  it("keeps the two locales in parity for this namespace", () => {
    // Scoped to `actions` deliberately: a whole-catalog diff would also
    // report every namespace already incomplete before this fix (`status`,
    // `legal`), which is a separate, pre-existing concern this task does not
    // own.
    expect(Object.keys(id.actions).sort()).toEqual(Object.keys(en.actions).sort());
  });
});
