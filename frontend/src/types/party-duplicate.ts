import type { PartyType } from "@/types/party";

/**
 * Advisory duplicate detection, as the interface meets it (D-084, D-086).
 *
 * Every signal is a **deterministic equality test**. There is no score, no
 * confidence, no similarity, and no type here that could carry one — the API
 * reports which exact tests matched and nothing about how alike two records are,
 * because a confidence claim about identity is exactly what M2 has no authority
 * to express.
 */
export const INDIVIDUAL_DUPLICATE_SIGNALS = [
  "NIK_EXACT",
  "NPWP_EXACT",
  "EMAIL_EXACT",
  "PHONE_EXACT",
  "NAME_BIRTH_DATE_EXACT",
] as const;

export const COMPANY_DUPLICATE_SIGNALS = [
  "TAX_ID_EXACT",
  "REGISTRATION_NUMBER_EXACT",
  "LEGAL_NAME_EXACT",
  "EMAIL_EXACT",
  "PHONE_EXACT",
] as const;

export type IndividualDuplicateSignal = (typeof INDIVIDUAL_DUPLICATE_SIGNALS)[number];
export type CompanyDuplicateSignal = (typeof COMPANY_DUPLICATE_SIGNALS)[number];
export type DuplicateSignal = IndividualDuplicateSignal | CompanyDuplicateSignal;

/** Every signal code the interface can label, for a defensive render-time check. */
export const KNOWN_DUPLICATE_SIGNALS: ReadonlySet<string> = new Set<string>([
  ...INDIVIDUAL_DUPLICATE_SIGNALS,
  ...COMPANY_DUPLICATE_SIGNALS,
]);

/**
 * One candidate the office may want to look at before saving.
 *
 * Minimal by design and by payload: a name, a type, an Office, and which tests
 * matched. **No identifier value of any kind** — not the NIK that matched, not a
 * mask of it, not the fingerprint that was compared. Knowing *that* a NIK
 * matched is the disclosure the sensitive permission authorizes; the value
 * itself belongs to the reviewed reveal surface (D-082).
 */
export type DuplicateCandidate = {
  id: string;
  party_type: PartyType;
  display_name: string | null;
  office: { id: string; code: string; name: string } | null;
  signals: DuplicateSignal[];
};

/**
 * The response envelope. `meta.advisory` is the API saying plainly what this is;
 * a client that treated it as an adjudication would be misreading a labelled
 * result.
 */
export type DuplicateCandidateResult = {
  data: DuplicateCandidate[];
  meta: { advisory: boolean };
};

/**
 * What an Individual check may compare on.
 *
 * `office_id` is sent **only** when creating — on update the backend takes the
 * Office from the record being edited and rejects the field, because naming an
 * Office while editing would be a search box for a different one. There is no
 * `exclude_party_id`: the subject is excluded server-side, and accepting a
 * client-supplied id would let a caller suppress an inconvenient candidate from
 * somebody else's review (D-084).
 *
 * `nik` and `npwp` are present because the *identity* surface may check them.
 * The ordinary profile form must not send them — it does not collect them, and
 * asking about an identifier requires that identifier's own full-view capability.
 */
export type IndividualDuplicateCheckInput = {
  office_id?: string;
  full_name?: string;
  birth_date?: string;
  primary_email?: string;
  primary_phone?: string;
  nik?: string;
  npwp?: string;
};

/**
 * What a Company check may compare on.
 *
 * `tax_id` is deliberately absent from the ordinary create and edit forms — M2.3
 * keeps it on the identity surface under its own permission, and adding it to
 * the profile form to improve duplicate detection would quietly make
 * `companies.update` a superset of `parties.identity.update`.
 */
export type CompanyDuplicateCheckInput = {
  office_id?: string;
  legal_name?: string;
  registration_number?: string;
  primary_email?: string;
  primary_phone?: string;
  tax_id?: string;
};
