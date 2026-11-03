import { apiRequest } from "@/services/apiClient";
import type { DailyDocument, DailyDocumentsPage } from "@/types/dailyDocument";

export async function listDailyDocuments(
  date: string,
  page: number,
  signal?: AbortSignal,
) {
  return (
    await apiRequest<{ data: DailyDocumentsPage }>(
      `/api/ai/daily-documents?date=${encodeURIComponent(date)}&page=${page}`,
      { signal },
    )
  ).data;
}
export async function requestDailyDocument(reportId: number) {
  return (
    await apiRequest<{ data: DailyDocument }>(
      `/api/ai/daily-documents/reports/${reportId}`,
      { method: "POST", body: "{}" },
    )
  ).data;
}
export async function retryDailyDocument(documentId: number) {
  return (
    await apiRequest<{ data: DailyDocument }>(
      `/api/ai/daily-documents/${documentId}/retry`,
      { method: "POST", body: "{}" },
    )
  ).data;
}
