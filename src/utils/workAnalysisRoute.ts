import type { AnalysisFilters } from "@/types/workReportAnalysis";
import { addDaysToDateOnly, isDateOnly } from "./dateOnly";

const positiveId = (value: string | null): number | null => {
  if (!value || !/^[1-9]\d*$/.test(value)) return null;
  const id = Number(value);
  return Number.isSafeInteger(id) ? id : null;
};

export function workAnalysisRoute(params: URLSearchParams, today: string) {
  // Both existing period-1 links and the old daily date links open the daily tab.
  const daily = params.get("view") === "daily" || params.get("days") === "1";
  const end = params.get("end") ?? (daily ? params.get("date") : null);
  const filters: AnalysisFilters = {
    end_date: end && isDateOnly(end) ? end : addDaysToDateOnly(today, -1),
    days: daily ? 1 : params.get("days") === "30" ? 30 : 7,
    department_id: positiveId(params.get("department")),
  };
  return { daily, filters, historyId: positiveId(params.get("analysis")) };
}

export function workAnalysisSearch(
  filters: AnalysisFilters,
  analysisId?: number,
) {
  return new URLSearchParams({
    ...(filters.days === 1 ? { view: "daily" } : {}),
    ...(analysisId ? { analysis: String(analysisId) } : {}),
    end: filters.end_date,
    days: String(filters.days),
    ...(filters.department_id
      ? { department: String(filters.department_id) }
      : {}),
  });
}
