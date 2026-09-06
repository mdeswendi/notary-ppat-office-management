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

/**
 * `notary.documentSlots.minuta` (the deed's own document pointer, one of three
 * slots on `DeedDocuments`) and `notary.minuta.title` (the Minuta Akta filing
 * section) used to share the literal string "Minuta Akta" in both locales.
 * The two answer different questions — the Minuta consistency audit found
 * that `notary_deeds.minuta_document_id` and `notary_minuta.document_id` are
 * deliberately independent columns (a deed may carry one, the other, both or
 * neither) — so an identical label made an ordinary, expected difference read
 * as a contradiction on the Deed Detail page. This file reads the real
 * catalog rather than the `next-intl` mock so a future edit that reintroduces
 * the collision fails here.
 */
describe("notary Minuta label collision", () => {
  it("keeps the canonical legal term for the filing section title in both locales", () => {
    // "Minuta Akta" is preserved untranslated per docs/05_I18N_LEGAL_TERMINOLOGY.md
    // — it is never the label this task is allowed to change.
    expect(id.notary.minuta.title).toBe("Minuta Akta");
    expect(en.notary.minuta.title).toBe("Minuta Akta");
  });

  it("gives the deed's own document slot a label distinct from the filing title", () => {
    expect(id.notary.documentSlots.minuta).toBe("Dokumen Minuta pada Akta");
    expect(en.notary.documentSlots.minuta).toBe("Minuta Document");
  });

  it("never lets the two Minuta labels collide again, in either locale", () => {
    expect(id.notary.documentSlots.minuta).not.toBe(id.notary.minuta.title);
    expect(en.notary.documentSlots.minuta).not.toBe(en.notary.minuta.title);
  });
});
