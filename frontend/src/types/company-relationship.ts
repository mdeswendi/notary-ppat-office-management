/**
 * Company relationship types, split by the permission surface each answers to.
 *
 * Stable codes mirroring the backend enum. The split is not cosmetic:
 * `companies.management.*` and `companies.shareholders.*` are independent
 * capabilities, and neither implies the other, so the two form controls must
 * never offer each other's codes.
 */
export const MANAGEMENT_RELATIONSHIP_TYPES = [
  "DIRECTOR",
  "COMMISSIONER",
  "AUTHORIZED_PERSON",
] as const;

export const OWNERSHIP_RELATIONSHIP_TYPES = ["SHAREHOLDER", "BENEFICIAL_OWNER"] as const;

export type ManagementRelationshipType = (typeof MANAGEMENT_RELATIONSHIP_TYPES)[number];
export type OwnershipRelationshipType = (typeof OWNERSHIP_RELATIONSHIP_TYPES)[number];
export type CompanyRelationshipType = ManagementRelationshipType | OwnershipRelationshipType;

/**
 * The person a relationship points at, as a relationship surface sees them.
 *
 * Note what has no type here: no `nik`, no `npwp`, no mask, no birth data, no
 * contact details. A relationship view permission is not a sensitive identity
 * permission (D-082), and the backend sends none of it — so no component can
 * render any of it by accident.
 *
 * `is_archived` exists because a historical relationship survives the person
 * being retired from the directory, and the interface should say so rather than
 * present them as current staff.
 */
export type RelationshipIndividual = {
  id: string;
  display_name: string | null;
  is_archived: boolean;
};

type RelationshipBase = {
  id: string;
  effective_from: string | null;
  effective_until: string | null;
  /** Exactly `effective_until === null`. Never a comparison against today. */
  is_current: boolean;
  individual: RelationshipIndividual | null;
  created_at: string | null;
};

/**
 * One management relationship. No `ownership_percentage`: that belongs to the
 * ownership surface, under its own permission.
 */
export type CompanyManagementRelationship = RelationshipBase & {
  relationship_type: ManagementRelationshipType;
  position_name: string | null;
};

/**
 * One ownership relationship. No `position_name`: that belongs to the management
 * surface.
 *
 * `ownership_percentage` is a string from the decimal cast, or null — and null
 * is not zero: an unrecorded holding and a zero holding are different facts.
 * Nothing is derived from it.
 */
export type CompanyShareholderRelationship = RelationshipBase & {
  relationship_type: OwnershipRelationshipType;
  ownership_percentage: string | null;
};

/**
 * A candidate for a new relationship.
 *
 * Deliberately two fields. The relationship update permission authorizes this
 * list rather than `parties.view`, so the payload is correspondingly narrow.
 */
export type RelationshipCandidate = {
  id: string;
  display_name: string | null;
};

export type CompanyRelationshipOptions<T extends string> = {
  individuals: RelationshipCandidate[];
  relationship_types: T[];
};

export type AddManagementInput = {
  individual_id: string;
  relationship_type: ManagementRelationshipType;
  position_name?: string | null;
  effective_from?: string | null;
};

export type AddShareholderInput = {
  individual_id: string;
  relationship_type: OwnershipRelationshipType;
  ownership_percentage?: string | null;
  effective_from?: string | null;
};

/**
 * Ending a relationship changes when it ended and nothing about what it was.
 * The date is explicit — the API refuses to invent one.
 */
export type EndRelationshipInput = {
  effective_until: string;
};
