import { apiClient } from "@/lib/api/client";
import { getMatters } from "@/services/matters";
import { getPartyDirectory } from "@/services/parties";
import { getProjects } from "@/services/projects";
import type {
  DocumentRelationCandidate,
  DocumentRelationInput,
  DocumentRelations,
  DocumentRelationType,
} from "@/types/document-relation";

const ROOT = "/api/v1/documents";

/**
 * Query keys for a Document's attachments.
 *
 * Nested under the document's own detail branch, so invalidating one document
 * refetches its relations without touching another's.
 */
export const documentRelationKeys = {
  all: (documentId: string) => ["documents", "detail", documentId, "relations"] as const,
  candidates: (type: DocumentRelationType, search: string) =>
    ["documents", "relation-candidates", type, search] as const,
};

export async function getDocumentRelations(documentId: string): Promise<DocumentRelations> {
  const response = await apiClient.get<{ data: DocumentRelations }>(
    `${ROOT}/${documentId}/relations`,
  );

  return response.data.data;
}

/**
 * Attach a Document to a Party, Project or Matter.
 *
 * Returns the **full relation set** rather than the row just created, because the
 * endpoint does: one response means the caller never has to merge a new row into
 * a list it might have refetched in between.
 */
export async function attachDocument(
  documentId: string,
  input: DocumentRelationInput,
): Promise<DocumentRelations> {
  const response = await apiClient.post<{ data: DocumentRelations }>(
    `${ROOT}/${documentId}/relations`,
    input,
  );

  return response.data.data;
}

/**
 * Remove an attachment.
 *
 * **`DELETE` with a body**, which Axios needs told explicitly — the second
 * argument to `delete()` is the config, not the payload, so a body passed the way
 * `post()` takes one is silently dropped and the request arrives with no
 * `entity_type`.
 */
export async function detachDocument(
  documentId: string,
  input: DocumentRelationInput,
): Promise<DocumentRelations> {
  const response = await apiClient.delete<{ data: DocumentRelations }>(
    `${ROOT}/${documentId}/relations`,
    { data: input },
  );

  return response.data.data;
}

/**
 * Records the caller may attach a document to.
 *
 * **Each type asks its own existing list endpoint**, so every candidate is already
 * bounded by that domain's own capability and Data Scope — a picker can never
 * offer something the attach endpoint would then refuse for lack of reach.
 *
 * **Matters are fetched from both domain roots and merged.** `notary.matters.view`
 * and `ppat.matters.view` are independent codes (D-101), so an actor may hold one
 * and not the other; two requests is what asking honestly costs. A failure in one
 * domain is not allowed to empty the other — each is caught so a Notary-only actor
 * still sees their Notary matters instead of an error.
 */
export async function getRelationCandidates(
  type: DocumentRelationType,
  search: string,
): Promise<DocumentRelationCandidate[]> {
  if (type === "party") {
    const page = await getPartyDirectory({ page: 1, per_page: 20, search });

    return page.data.map((entry) => ({
      id: entry.id,
      entity_type: "party" as const,
      label: entry.display_name ?? "—",
      reference: null,
    }));
  }

  if (type === "project") {
    const page = await getProjects({ page: 1, search, status: "", priority: "" });

    return page.data.map((project) => ({
      id: project.id,
      entity_type: "project" as const,
      label: project.title,
      reference: project.project_number,
    }));
  }

  const domains = ["NOTARY", "PPAT"] as const;

  const results = await Promise.all(
    domains.map(async (domain) => {
      try {
        const page = await getMatters(domain, { page: 1, search, status: "", priority: "" });

        return page.data.map((matter) => ({
          id: matter.id,
          entity_type: "matter" as const,
          label: matter.title,
          reference: matter.matter_number,
          domain,
        }));
      } catch {
        // An actor holding only one domain's capability gets 403 for the other.
        // That is an ordinary outcome here, not a fault: the list simply carries
        // what they may see.
        return [];
      }
    }),
  );

  return results.flat();
}
