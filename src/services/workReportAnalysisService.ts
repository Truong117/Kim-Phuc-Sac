import { apiRequest } from "@/services/apiClient";
import type {
  AnalysisFilters,
  AnalysisPreview,
  WorkReportAnalysis,
  AnalysisRegeneration,
} from "@/types/workReportAnalysis";

const root = "/api/ai/work-report-analyses";
const data = async <T>(path: string, init?: RequestInit): Promise<T> =>
  (await apiRequest<{ data: T }>(root + path, init)).data;
export const previewWorkAnalysis = (
  filters: AnalysisFilters,
  signal?: AbortSignal,
) => {
  const query = new URLSearchParams({
    end_date: filters.end_date,
    days: String(filters.days),
  });
  if (filters.department_id !== null)
    query.set("department_id", String(filters.department_id));
  return data<AnalysisPreview>(`/preview?${query}`, { signal });
};
export const getWorkAnalysis = (id: number, signal?: AbortSignal) =>
  data<WorkReportAnalysis>(`/${id}`, { signal });
export const getWorkAnalysisHistory = (signal?: AbortSignal) =>
  data<WorkReportAnalysis[]>("/history", { signal });
export const createWorkAnalysis = (
  filters: AnalysisFilters,
  signal?: AbortSignal,
) =>
  data<WorkReportAnalysis>("", {
    method: "POST",
    body: JSON.stringify(filters),
    signal,
  });
export const retryWorkAnalysis = (id: number, signal?: AbortSignal) =>
  data<WorkReportAnalysis>(`/${id}/retry`, { method: "POST", signal });
export const regenerateWorkAnalysis = (
  id: number,
  key: string,
  feedback: string,
  signal?: AbortSignal,
) =>
  apiRequest<AnalysisRegeneration>(`${root}/${id}/regenerate`, {
    method: "POST",
    body: JSON.stringify({ idempotency_key: key, review_feedback: feedback }),
    signal,
  });
export const getAnalysisRegeneration = (key: string, signal?: AbortSignal) =>
  apiRequest<AnalysisRegeneration>(`${root}/regenerations/${key}`, { signal });
