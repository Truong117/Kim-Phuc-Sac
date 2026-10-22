import { apiRequest } from "@/services/apiClient";
import type {
  AiExecutiveDashboardData,
  AiExecutivePeriodKey,
} from "@/types/aiExecutiveDashboard";

type AiExecutiveDashboardResponse = { data: AiExecutiveDashboardData };

export const getAiExecutiveDashboard = async (
  period: AiExecutivePeriodKey,
  signal?: AbortSignal,
): Promise<AiExecutiveDashboardData> => {
  const response = await apiRequest<AiExecutiveDashboardResponse>(
    `/api/ai/executive-dashboard?period=${encodeURIComponent(period)}`,
    { signal },
  );

  return response.data;
};
