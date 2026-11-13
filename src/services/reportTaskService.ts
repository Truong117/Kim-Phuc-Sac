import { apiRequest } from "./apiClient";
import type { ManagedTask, TaskOutput } from "@/types/tasks";

export type AssignedReportTasks = {
  data: ManagedTask[];
  schema_ready: boolean;
  can_sync?: boolean;
  meta?: { current_page: number; last_page: number; total: number };
};
export const getAssignedReportTasks = (
  date: string,
  page: number,
  search: string,
  signal?: AbortSignal,
) =>
  apiRequest<AssignedReportTasks>(
    `/api/reports/assigned-tasks?${new URLSearchParams({ report_date: date, page: String(page), search })}`,
    { signal, cache: "no-store" },
  );
export type TaskProgressEvent = {
  origin?: "daily_report" | "historical_import";
  id: number;
  report_id: number;
  report_item_id: number | null;
  report_date: string;
  applied: boolean;
  status_before: string;
  status_after: string;
  outputs: TaskOutput[];
  created_at: string;
};
export const getTaskProgressHistory = (
  id: number,
  page: number,
  signal?: AbortSignal,
) =>
  apiRequest<{
    data: TaskProgressEvent[];
    meta: { current_page: number; last_page: number };
  }>(`/api/tasks/${id}/progress?page=${page}`, { signal, cache: "no-store" });
