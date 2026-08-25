import { apiClient } from "@/lib/api/client";
import type {
  MatterPropertyList,
  Property,
  PropertyCreateInput,
  PropertyListPage,
  PropertyListQuery,
  PropertyOptions,
  PropertyOwner,
  PropertyOwnerCreateInput,
  PropertyOwnerList,
  PropertyOwnerUpdateInput,
  PropertyUpdateInput,
} from "@/types/property";

const ROOT = "/api/v1/properties";

/**
 * Query keys for the Property surface (M7.3, D-121).
 *
 * **`properties`, not `ppat.properties`.** The canonical capability family is
 * `properties.*` — there is no `ppat.properties.*` in the catalogue — so the API root
 * and these keys match the codes. The *page* lives under `/ppat/properties`, because
 * `CLAUDE.md` section 16 lists Property among the PPAT-specific concepts. A page path
 * is not a permission namespace, and the asymmetry is deliberate.
 *
 * The chain of title is keyed **under** its Property, because it has no existence
 * apart from one — mirroring the address, which has no `/property-owners/{id}` form.
 */
export const propertyKeys = {
  all: () => ["properties"] as const,
  list: (query: PropertyListQuery) => ["properties", "list", query] as const,
  detail: (id: string) => ["properties", "detail", id] as const,
  owners: (id: string) => ["properties", "detail", id, "owners"] as const,
  options: () => ["properties", "options"] as const,
  matterProperties: (matterId: string) => ["ppat", "matters", matterId, "properties"] as const,
};

export async function getProperties(query: PropertyListQuery): Promise<PropertyListPage> {
  const blank = (value: string | undefined) => (value === "" ? undefined : value);

  const response = await apiClient.get<PropertyListPage>(ROOT, {
    params: {
      page: query.page,
      per_page: query.per_page,
      search: blank(query.search),
      property_type: blank(query.property_type),
      right_type: blank(query.right_type),
      certificate_number: blank(query.certificate_number),
      city: blank(query.city),
      district: blank(query.district),
      province: blank(query.province),
      village: blank(query.village),
      owner_party_id: blank(query.owner_party_id),
      matter_id: blank(query.matter_id),
      project_id: blank(query.project_id),
      archived: blank(query.archived),
    },
  });

  return response.data;
}

export async function getProperty(id: string): Promise<Property> {
  const response = await apiClient.get<{ data: Property }>(`${ROOT}/${id}`);

  return response.data.data;
}

export async function getPropertyOptions(): Promise<PropertyOptions["data"]> {
  const response = await apiClient.get<PropertyOptions>(`${ROOT}/options`);

  return response.data.data;
}

export async function createProperty(input: PropertyCreateInput): Promise<Property> {
  const response = await apiClient.post<{ data: Property }>(ROOT, input);

  return response.data.data;
}

/**
 * Correct a Property's own fields.
 *
 * **`PATCH`, not `PUT`.** The brief specified `PUT`; the repository reserves that verb
 * for full replacement and every partial update since M2 is a `PATCH`. This sends the
 * fields that changed.
 *
 * `property_number` is not in the input type: a reference belongs to the record that
 * received it (D-103), and the API answers 422 rather than ignoring it.
 */
export async function updateProperty(id: string, input: PropertyUpdateInput): Promise<Property> {
  const response = await apiClient.patch<{ data: Property }>(`${ROOT}/${id}`, input);

  return response.data.data;
}

/**
 * Retire a Property from the office's active reference list.
 *
 * **This is the soft delete, and the only retirement path.** `properties.delete` is
 * absent from the catalogue while `properties.archive` is present, and the ERD gave
 * `properties` a `deleted_at` — read together they are one mechanism. `status` is not
 * written, because the column has no canonical vocabulary at all.
 *
 * **It destroys nothing**: every link in the chain of title and every Matter junction
 * survives, and the record stays readable through `?archived=1`.
 *
 * **It cannot be undone through the product.** There is no `properties.restore` in the
 * catalogue, unlike `projects.restore` (O-045), so the interface confirms before
 * calling this.
 */
export async function archiveProperty(id: string): Promise<Property> {
  const response = await apiClient.patch<{ data: Property }>(`${ROOT}/${id}/archive`);

  return response.data.data;
}

/**
 * The whole chain of title, newest first.
 *
 * Its own capability — `properties.ownership.view` — so a caller who may read the
 * parcel and not its title gets a 403 here and the section says so honestly.
 */
export async function getPropertyOwners(propertyId: string): Promise<PropertyOwnerList> {
  const response = await apiClient.get<PropertyOwnerList>(`${ROOT}/${propertyId}/owners`);

  return response.data;
}

/**
 * Add a link to the chain.
 *
 * **`supersedes_current` decides which act this is** — a transfer that closes the
 * current holders, or a co-owner added beside them. Several links may be current at
 * once: co-ownership is ordinary, and the M7 lock section 7.2 is explicit that
 * `is_current` is a flag on many rows rather than a pointer to one.
 */
export async function addPropertyOwner(
  propertyId: string,
  input: PropertyOwnerCreateInput,
): Promise<PropertyOwner> {
  const response = await apiClient.post<{ data: PropertyOwner }>(
    `${ROOT}/${propertyId}/owners`,
    input,
  );

  return response.data.data;
}

/**
 * Correct a link, or close it.
 *
 * **There is no remove.** `property_owners` has no `deleted_at` in the ERD, so a delete
 * could only be a hard one, and hard-deleting a link destroys the history the table
 * exists to keep (`CLAUDE.md` sections 30 and 63). Ending an ownership is stamping
 * `effective_until`, which this does.
 */
export async function updatePropertyOwner(
  propertyId: string,
  ownerId: string,
  input: PropertyOwnerUpdateInput,
): Promise<PropertyOwner> {
  const response = await apiClient.patch<{ data: PropertyOwner }>(
    `${ROOT}/${propertyId}/owners/${ownerId}`,
    input,
  );

  return response.data.data;
}

/*
|--------------------------------------------------------------------------
| Which land a PPAT Matter concerns
|--------------------------------------------------------------------------
*/

const MATTER_ROOT = "/api/v1/ppat/matters";

/**
 * The parcels a Matter names.
 *
 * **PPAT only.** `CLAUDE.md` section 16 lists Property among the PPAT-specific
 * concepts, so there is no Notary counterpart address.
 */
export async function getMatterProperties(matterId: string): Promise<MatterPropertyList> {
  const response = await apiClient.get<MatterPropertyList>(`${MATTER_ROOT}/${matterId}/properties`);

  return response.data;
}

/**
 * Name a Property as land this Matter concerns.
 *
 * Two capabilities: `ppat.matters.update` composes the Matter, and `properties.view`
 * reaches the target. Re-attaching the same parcel corrects its `role_code` rather
 * than erroring — one row per pair.
 */
export async function attachMatterProperty(
  matterId: string,
  input: { property_id: string; role_code?: string | null },
): Promise<void> {
  await apiClient.post(`${MATTER_ROOT}/${matterId}/properties`, input);
}

/**
 * Stop naming a Property as land this Matter concerns.
 *
 * **The junction row only** — never the Matter, never the Property, never a link in a
 * chain of title. `matter_properties` has no `deleted_at`, so this is a hard delete and
 * the interface says so rather than implying an undo.
 */
export async function detachMatterProperty(matterId: string, propertyId: string): Promise<void> {
  await apiClient.delete(`${MATTER_ROOT}/${matterId}/properties/${propertyId}`);
}
