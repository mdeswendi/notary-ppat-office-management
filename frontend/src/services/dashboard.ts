import { apiClient } from "@/lib/api/client";
import type {
  ActivityItem,
  DashboardDeeds,
  DashboardStats,
  DashboardTaskBuckets,
  NeedsAttentionItem,
  WorkloadItem,
} from "@/types/dashboard";

const ROOT = "/api/v1/dashboard";

/**
 * The Dashboard surface (M8.1, D-122).
 *
 * **Six calls rather than one.** The panels have very different costs, so a
 * single request would make the cheapest wait for the most expensive; separate
 * keys also let the task queue refresh without recomputing deed histograms.
 *
 * Every response may carry `null` where the caller holds no capability for that
 * panel. No client-side permission check is needed or wanted here — the server
 * decides, and the widget renders nothing when it says `null`.
 */
export const dashboardQueryKeys = {
  all: () => ["dashboard"] as const,
  stats: () => ["dashboard", "stats"] as const,
  tasks: () => ["dashboard", "tasks"] as const,
  needsAttention: () => ["dashboard", "needs-attention"] as const,
  workload: () => ["dashboard", "workload"] as const,
  activity: (limit: number) => ["dashboard", "activity", limit] as const,
  deeds: () => ["dashboard", "deeds"] as const,
};

export async function getDashboardStats(): Promise<DashboardStats> {
  const response = await apiClient.get<{ data: DashboardStats }>(`${ROOT}/stats`);

  return response.data.data;
}

export async function getDashboardTasks(): Promise<DashboardTaskBuckets | null> {
  const response = await apiClient.get<{ data: DashboardTaskBuckets | null }>(`${ROOT}/tasks`);

  return response.data.data;
}

export async function getDashboardNeedsAttention(): Promise<NeedsAttentionItem[] | null> {
  const response = await apiClient.get<{ data: NeedsAttentionItem[] | null }>(
    `${ROOT}/needs-attention`,
  );

  return response.data.data;
}

export async function getDashboardWorkload(): Promise<WorkloadItem[] | null> {
  const response = await apiClient.get<{ data: WorkloadItem[] | null }>(`${ROOT}/workload`);

  return response.data.data;
}

export async function getDashboardActivity(limit = 20): Promise<ActivityItem[]> {
  const response = await apiClient.get<{ data: ActivityItem[] }>(`${ROOT}/activity`, {
    params: { limit },
  });

  return response.data.data;
}

export async function getDashboardDeeds(): Promise<DashboardDeeds> {
  const response = await apiClient.get<{ data: DashboardDeeds }>(`${ROOT}/deeds`);

  return response.data.data;
}
