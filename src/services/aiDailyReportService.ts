import { apiRequest } from "@/services/apiClient";
import type {
  AiDailyAvailableDates,
  AiDailyReport,
  AiDailySummary,
} from "@/types/aiDailyReport";

type DataResponse<T> = { data: T };

export const getAiDailyAvailableDates = async (signal?: AbortSignal) => {
  const response = await apiRequest<DataResponse<AiDailyAvailableDates>>(
    "/api/ai/daily-reports/available-dates",
    { signal },
  );
  return response.data;
};

export const getAiDailySummary = async (date: string, signal?: AbortSignal) => {
  const response = await apiRequest<DataResponse<AiDailySummary>>(
    `/api/ai/daily-reports/summary?date=${encodeURIComponent(date)}`,
    { signal },
  );
  return response.data;
};

export const getAiDailyHistory = async (signal?: AbortSignal) => {
  const response = await apiRequest<DataResponse<AiDailyReport[]>>(
    "/api/ai/daily-reports/history",
    { signal },
  );
  return response.data;
};

export const findAiDailyReport = async (date: string, signal?: AbortSignal) => {
  const response = await apiRequest<DataResponse<AiDailyReport | null>>(
    `/api/ai/daily-reports?date=${encodeURIComponent(date)}`,
    { signal },
  );
  return response.data;
};

export const createAiDailyReport = async (date: string) => {
  const response = await apiRequest<DataResponse<AiDailyReport>>(
    "/api/ai/daily-reports",
    { method: "POST", body: JSON.stringify({ date }) },
  );
  return response.data;
};

export const getAiDailyReport = async (id: number, signal?: AbortSignal) => {
  const response = await apiRequest<DataResponse<AiDailyReport>>(
    `/api/ai/daily-reports/${id}`,
    { signal },
  );
  return response.data;
};
