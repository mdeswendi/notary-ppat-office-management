import { apiClient } from "@/lib/api/client";
import type {
  Document,
  DocumentListPage,
  DocumentListQuery,
  DocumentOptions,
  DocumentUpdateInput,
  DocumentUploadInput,
} from "@/types/document";

const ROOT = "/api/v1/documents";

/**
 * Query keys for the Document surface.
 *
 * **Not keyed by domain**, unlike Matter. `documents.*` is a single canonical
 * namespace with no Notary/PPAT split, so there is nothing for a key to separate
 * — the reasoning that split the Matter keys (D-101) has no counterpart here.
 *
 * Relation-filtered lists get their own key branch so a Matter's document section
 * and the global list do not share a cache entry: they are different questions
 * with different answers, and invalidating one should not silently refetch the
 * other.
 */
export const documentQueryKeys = {
  all: () => ["documents"] as const,
  list: (query: DocumentListQuery) => ["documents", "list", query] as const,
  detail: (id: string) => ["documents", "detail", id] as const,
  options: () => ["documents", "options"] as const,
};

export async function getDocuments(query: DocumentListQuery): Promise<DocumentListPage> {
  const response = await apiClient.get<DocumentListPage>(ROOT, {
    params: {
      page: query.page,
      per_page: query.per_page,
      search: query.search === "" ? undefined : query.search,
      status: query.status === "" ? undefined : query.status,
      document_type_code: query.document_type_code === "" ? undefined : query.document_type_code,
      is_sensitive: query.is_sensitive === "" ? undefined : query.is_sensitive,
      party_id: query.party_id === "" ? undefined : query.party_id,
      project_id: query.project_id === "" ? undefined : query.project_id,
      matter_id: query.matter_id === "" ? undefined : query.matter_id,
      sort_by: query.sort_by,
      sort_direction: query.sort_direction,
    },
  });

  return response.data;
}

export async function getDocument(id: string): Promise<Document> {
  const response = await apiClient.get<{ data: Document }>(`${ROOT}/${id}`);

  return response.data.data;
}

export async function getDocumentOptions(): Promise<DocumentOptions["data"]> {
  const response = await apiClient.get<DocumentOptions>(`${ROOT}/options`);

  return response.data.data;
}

/**
 * File a Document and its first version.
 *
 * **`multipart/form-data`, built here rather than by the caller**, so every upload
 * shapes the payload the same way. Two details are easy to get wrong and are
 * handled once:
 *
 * `is_sensitive` is sent as `"1"` / `"0"` rather than `"true"` / `"false"`,
 * because a multipart body carries strings and Laravel's `boolean` rule accepts
 * the former. `"false"` would arrive as a non-empty string and validate as **true**
 * — silently marking every document sensitive.
 *
 * `related_to` is sent as bracketed keys, which is how PHP parses a nested array
 * out of multipart. An empty relation is omitted entirely rather than sent blank.
 *
 * The `Content-Type` header is deliberately not set: the browser must add its own
 * `boundary` parameter, and overriding it produces a body the server cannot parse.
 */
export async function uploadDocument(input: DocumentUploadInput): Promise<Document> {
  const body = new FormData();

  body.append("title", input.title);
  body.append("file", input.file);

  if (input.document_type_code) {
    body.append("document_type_code", input.document_type_code);
  }

  body.append("is_sensitive", input.is_sensitive ? "1" : "0");

  for (const field of ["document_date", "expiry_date", "notes"] as const) {
    const value = input[field];

    if (value) {
      body.append(field, value);
    }
  }

  for (const key of ["party_id", "project_id", "matter_id"] as const) {
    const value = input.related_to?.[key];

    if (value) {
      body.append(`related_to[${key}]`, value);
    }
  }

  const response = await apiClient.post<{ data: Document }>(ROOT, body);

  return response.data.data;
}

/**
 * Correct a Document's metadata.
 *
 * Never the file: a replacement is a new version, and sending `file` here is a
 * 422 by design.
 */
export async function updateDocument(id: string, input: DocumentUpdateInput): Promise<Document> {
  const response = await apiClient.patch<{ data: Document }>(`${ROOT}/${id}`, input);

  return response.data.data;
}

export async function verifyDocument(id: string): Promise<Document> {
  const response = await apiClient.post<{ data: Document }>(`${ROOT}/${id}/verify`);

  return response.data.data;
}

export async function archiveDocument(id: string): Promise<Document> {
  const response = await apiClient.post<{ data: Document }>(`${ROOT}/${id}/archive`);

  return response.data.data;
}

export async function deleteDocument(id: string): Promise<void> {
  await apiClient.delete(`${ROOT}/${id}`);
}

/**
 * Fetch the file and hand it to the browser as a save.
 *
 * **Not a link.** The endpoint requires the session cookie and authorizes against
 * the record, so the file is fetched as a blob through the same client every other
 * request uses; an `<a href>` would work only by accident and would put a URL into
 * the page that looks transferable. There is no signed URL to link to, by design
 * (D-114).
 *
 * The object URL is revoked immediately after the click — it is a handle to memory
 * the browser holds until told otherwise, and leaving it alive would keep a copy of
 * a legal document in the tab for as long as it stays open.
 */
export async function downloadDocument(id: string, filename: string): Promise<void> {
  const response = await apiClient.get<Blob>(`${ROOT}/${id}/download`, {
    responseType: "blob",
  });

  const url = URL.createObjectURL(response.data);

  try {
    const anchor = window.document.createElement("a");

    anchor.href = url;
    anchor.download = filename;
    window.document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
  } finally {
    URL.revokeObjectURL(url);
  }
}
