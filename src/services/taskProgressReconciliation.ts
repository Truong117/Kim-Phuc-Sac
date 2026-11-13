import { apiRequest } from "./apiClient";
import type { TaskOutput, TaskProgress, TaskQuantityUnit } from "@/types/tasks";
export type ProgressSource = {
  report_item_id: number;
  report_id: number;
  report_date: string;
  content: string;
  result: string;
  evidence_links: string[];
  source_hash: string;
  already_recorded: boolean;
  linked_elsewhere: boolean;
  suggested_outputs: TaskOutput[];
};
export type ProgressSources = {
  data: ProgressSource[];
  meta: { current_page: number; last_page: number };
  expected_revision: number;
};
export type ReconciliationEntry = {
  report_item_id: number;
  source_hash: string;
  outputs: { name: string; reference?: string | null }[];
};
export const getProgressSources = (
  id: number,
  query: { from: string; to: string; search: string; page: number },
  signal?: AbortSignal,
) =>
  apiRequest<ProgressSources>(
    `/api/tasks/${id}/progress/reconciliation?${new URLSearchParams({ ...query, page: String(query.page) })}`,
    { signal, cache: "no-store" },
  );
export const importProgressSources = (
  id: number,
  input: {
    expected_revision: number;
    unit: TaskQuantityUnit;
    confirmed: boolean;
    confirmation_note: string;
    entries: ReconciliationEntry[];
  },
) =>
  apiRequest<{
    data: { created: number; imported_sources: number; progress: TaskProgress };
  }>(`/api/tasks/${id}/progress/reconciliation`, {
    method: "POST",
    body: JSON.stringify(input),
  });
