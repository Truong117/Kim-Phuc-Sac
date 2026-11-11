import { apiRequest } from "./apiClient";
import type { PersonalWeeklyReport } from "@/types/personalWeeklyReport";

export const getPersonalWeeklyReport = async (
  endDate: string,
  signal?: AbortSignal,
) =>
  (
    await apiRequest<{ data: PersonalWeeklyReport }>(
      `/api/reports/my-weekly-summary?${new URLSearchParams({ end_date: endDate })}`,
      { signal, cache: "no-store" },
    )
  ).data;
