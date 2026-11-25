import { apiRequest } from "@/services/apiClient";
import type {
  DashboardData,
  DashboardWeeklyReporting,
} from "@/types/dashboard";

type DashboardResponse = { data: DashboardData };

export const getDashboard = async (
  signal?: AbortSignal,
): Promise<DashboardData> => {
  const response = await apiRequest<DashboardResponse>("/api/dashboard", {
    signal,
  });

  return response.data;
};

export const getWeeklyReporting = async (
  weekStart?: string,
  signal?: AbortSignal,
): Promise<DashboardWeeklyReporting> => {
  const query = weekStart ? `?week_start=${encodeURIComponent(weekStart)}` : "";
  const response = await apiRequest<{ data: DashboardWeeklyReporting }>(
    `/api/dashboard/weekly-reporting${query}`,
    { signal },
  );
  return response.data;
};
