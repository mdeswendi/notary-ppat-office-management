/**
 * What a Document can be attached to (M5.3, D-118).
 *
 * **Three of seven, and the other four are blocked rather than deferred.**
 * `03_DATABASE_ERD.md` section 14 recommends seven junction tables; only three
 * have targets that exist. `property`, `notary_deed`, `ppat_deed` and
 * `matter_requirement` reference `properties` (M7), `notary_deeds` (M6),
 * `ppat_deeds` (M7) and `matter_requirements` — none of which is built, and a
 * composite foreign key cannot point at a table that is not there (D-115).
 *
 * The API refuses the missing four with a field error on `entity_type`, so this
 * union is the whole vocabulary the product accepts today. Adding one later means
 * adding a member here and a migration there, not redesigning.
 *
 * **There is no `notary_matter` / `ppat_matter` split**, deliberately. A Matter is
 * reached under `notary.matters.view` or `ppat.matters.view` depending on its own
 * stored `domain`, and that selection belongs to the backend. Splitting it here
 * would put the permission namespace into the request body, which is what D-101
 * forbids.
 */
export const DOCUMENT_RELATION_TYPES = ["party", "project", "matter"] as const;

export type DocumentRelationType = (typeof DOCUMENT_RELATION_TYPES)[number];

/**
 * One attached record, as the relations endpoint returns it.
 *
 * A **stub**: a label, an id, and enough to link to the surface that owns the
 * record. Never an embedded resource — opening a Party, Project or Matter is that
 * surface's own authorization decision.
 *
 * **No Party identity of any kind travels here** (D-082), and no `office_id`: the
 * carrier is a database constraint rather than information about the
 * relationship.
 */
export type DocumentRelation = {
  id: string;
  entity_type: DocumentRelationType;
  /** The record's own name — a Party's display name, a Project or Matter title. */
  label: string;
  /** The internal reference where one exists. Parties have none. */
  reference: string | null;
  attached_at: string | null;
  /** Party only. */
  party_type?: "INDIVIDUAL" | "COMPANY";
  /** Matter only — decides which surface the link points at. */
  domain?: "NOTARY" | "PPAT";
};

/**
 * Grouped by type, because the three lists are read separately and a flat array
 * would make every consumer re-group it.
 */
export type DocumentRelations = {
  parties: DocumentRelation[];
  projects: DocumentRelation[];
  matters: DocumentRelation[];
};

export type DocumentRelationInput = {
  entity_type: DocumentRelationType;
  entity_id: string;
};

/**
 * A candidate the attach picker offers.
 *
 * Deliberately the same shape as {@link DocumentRelation} minus the pivot, so one
 * row component renders both a search result and an existing attachment.
 */
export type DocumentRelationCandidate = {
  id: string;
  entity_type: DocumentRelationType;
  label: string;
  reference: string | null;
  domain?: "NOTARY" | "PPAT";
};
