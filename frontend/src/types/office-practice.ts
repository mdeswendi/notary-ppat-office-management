export const OFFICE_PRACTICE_TYPES = [
  "PPAT",
  "NOTARY",
  "NOTARY_AND_PPAT",
  "COLLABORATION",
] as const;
export const PROFESSION_TYPES = ["NOTARY", "PPAT"] as const;
export const PROFESSIONAL_RELATIONSHIP_TYPES = ["INTERNAL", "EXTERNAL"] as const;

export type OfficePracticeType = (typeof OFFICE_PRACTICE_TYPES)[number];
export type ProfessionType = (typeof PROFESSION_TYPES)[number];
export type ProfessionalRelationshipType = (typeof PROFESSIONAL_RELATIONSHIP_TYPES)[number];

export type ProfessionalAppointment = {
  id: string;
  profession_type: ProfessionType;
  relationship_type: ProfessionalRelationshipType;
  registration_number: string | null;
  appointed_at: string | null;
  ended_at: string | null;
  professional_office_name: string | null;
  office_city: string | null;
  province: string | null;
  jurisdiction: string | null;
  is_active: boolean;
  individual: { id: string; display_name: string | null; is_archived: boolean };
};

export type OfficePractice = {
  id: string;
  code: string;
  name: string;
  practice_type: OfficePracticeType | null;
  jurisdiction: string | null;
  can_update: boolean;
  professionals: ProfessionalAppointment[];
};

export type OfficePracticeOptions = {
  individuals: Array<{ id: string; display_name: string | null }>;
  profession_types: ProfessionType[];
  relationship_types: ProfessionalRelationshipType[];
};

export type UpdateOfficePracticeInput = {
  practice_type: OfficePracticeType;
  jurisdiction: string;
};

export type AddProfessionalAppointmentInput = {
  individual_id: string;
  profession_type: ProfessionType;
  relationship_type: ProfessionalRelationshipType;
  registration_number?: string;
  appointed_at?: string;
  professional_office_name?: string;
  office_city?: string;
  province?: string;
  jurisdiction?: string;
};
