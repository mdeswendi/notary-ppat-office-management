import type { CompanyEntityType } from "@/types/company";
import type {
  ManagementRelationshipType,
  OwnershipRelationshipType,
} from "@/types/company-relationship";

/**
 * The companies a person is involved with — M2.4's relationship surfaces, read
 * from the person's side (M2.5).
 *
 * **Read-only, and there is no input type in this file.** Relationships are
 * added and ended on the Company, where D-085's add-and-close model lives. A
 * mutation shape here would invite a second write path for the same rows.
 */

/**
 * The organization a relationship points at, as the reverse view sees it.
 *
 * Note what has no type here: no `tax_id`, no mask, no registration number, no
 * contact details. A relationship view permission is not a sensitive identity
 * permission (D-082), and the backend sends none of it.
 *
 * `can_view_company` is computed by the backend from the real Company policy,
 * **scope included**. A company in an Office the caller cannot reach is still
 * named — the person's history is about it — but it is not linkable, and this
 * flag is how the interface knows the difference without guessing from a
 * permission code.
 */
export type IndividualCompanySummary = {
  id: string;
  display_name: string | null;
  entity_type: CompanyEntityType | null;
  is_archived: boolean;
  can_view_company: boolean;
};

type IndividualCompanyBase = {
  id: string;
  effective_from: string | null;
  effective_until: string | null;
  /** Exactly `effective_until === null`. Never a comparison against today. */
  is_current: boolean;
  company: IndividualCompanySummary | null;
};

/**
 * One management involvement. No `ownership_percentage`: that belongs to the
 * ownership surface, under its own permission, and the backend omits it here.
 */
export type IndividualManagementCompany = IndividualCompanyBase & {
  relationship_type: ManagementRelationshipType;
  position_name: string | null;
};

/**
 * One ownership involvement. No `position_name`, for the mirror reason.
 *
 * `ownership_percentage` is a string from the decimal cast, or null — and null
 * is not zero: an unrecorded holding and a zero holding are different facts.
 * Nothing is derived from it.
 */
export type IndividualOwnershipCompany = IndividualCompanyBase & {
  relationship_type: OwnershipRelationshipType;
  ownership_percentage: string | null;
};
