import { apiRequest } from "@/services/apiClient";
import type { DashboardData } from "@/types/dashboard";

type DashboardResponse = { data: DashboardData };

export const getDashboard = async (
  signal?: AbortSignal,
): Promise<DashboardData> => {
  const response = await apiRequest<DashboardResponse>("/api/dashboard", {
    signal,
  });

  return response.data;
};
