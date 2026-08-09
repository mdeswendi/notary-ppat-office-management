import { apiClient } from "@/lib/api/client";
import type { ManagedUser, UserListPage, UserListQuery, UserOffice } from "@/types/user";

export const userQueryKeys = {
  all: ["users"] as const,
  list: (query: UserListQuery) => ["users", "list", query] as const,
  options: ["users", "options"] as const,
};

export async function getUsers(query: UserListQuery): Promise<UserListPage> {
  const response = await apiClient.get<UserListPage>("/api/v1/users", {
    params: {
      page: query.page,
      // Omitted rather than sent empty, so the request URL reflects what was
      // actually asked for.
      ...(query.search ? { search: query.search } : {}),
    },
  });

  return response.data;
}

/**
 * The active Offices this administrator may place a user in.
 *
 * Scope-filtered by the backend: an office-scoped administrator receives only
 * their own Office. The form never decides this for itself.
 */
export async function getUserOffices(): Promise<UserOffice[]> {
  const response = await apiClient.get<{ data: { offices: UserOffice[] } }>(
    "/api/v1/users/options",
  );

  return response.data.data.offices;
}

export type CreateUserInput = {
  name: string;
  email: string;
  phone: string | null;
  office_id: string;
  password: string;
  password_confirmation: string;
};

export async function createUser(input: CreateUserInput): Promise<ManagedUser> {
  const response = await apiClient.post<{ data: ManagedUser }>("/api/v1/users", input);

  return response.data.data;
}

export type UpdateUserInput = {
  name: string;
  email: string;
  phone: string | null;
  office_id: string;
};

/**
 * Administrative fields only. There is deliberately no password here — changing
 * somebody's password is an account-security operation with its own flow, and
 * the backend rejects the field outright.
 */
export async function updateUser(id: string, input: UpdateUserInput): Promise<ManagedUser> {
  const response = await apiClient.patch<{ data: ManagedUser }>(`/api/v1/users/${id}`, input);

  return response.data.data;
}

/**
 * Activation is its own endpoint rather than a field on the edit form, so
 * turning an account off is always a deliberate act. Disabling your own account
 * answers 409.
 */
export async function setUserActivation(id: string, active: boolean): Promise<ManagedUser> {
  const response = await apiClient.post<{ data: ManagedUser }>(
    `/api/v1/users/${id}/${active ? "enable" : "disable"}`,
  );

  return response.data.data;
}
