import type {
  AiExecutiveDashboardData,
  AiExecutiveDailyTrend,
} from "@/types/aiExecutiveDashboard";

export type ExecutiveKpiKey =
  | "reports"
  | "workItems"
  | "completed"
  | "inProgress"
  | "blocked"
  | "reportingRate";

export type ExecutiveKpi = {
  key: ExecutiveKpiKey;
  value: number | null;
  percentage?: boolean;
};

export const buildExecutiveKpis = (
  dashboard: AiExecutiveDashboardData,
): ExecutiveKpi[] => [
  { key: "reports", value: dashboard.totals.reports_submitted },
  { key: "workItems", value: dashboard.totals.work_items.total },
  { key: "completed", value: dashboard.totals.work_items.completed },
  { key: "inProgress", value: dashboard.totals.work_items.in_progress },
  { key: "blocked", value: dashboard.totals.blocked_items },
  {
    key: "reportingRate",
    value: dashboard.reporting.rate_percent,
    percentage: true,
  },
];

export const hasExecutiveTrendData = (rows: AiExecutiveDailyTrend[]) =>
  rows.some(
    (row) =>
      row.reports_submitted > 0 ||
      row.work_items.completed > 0 ||
      row.work_items.in_progress > 0 ||
      row.work_items.blocked > 0,
  );

export type ExecutiveTrendFacts = {
  observedDays: number;
  totalDays: number;
  peakDate: string;
  peakWorkItems: number;
  blockedItems: number;
  blockedDays: number;
};

export const buildExecutiveTrendFacts = (
  rows: AiExecutiveDailyTrend[],
): ExecutiveTrendFacts | null => {
  const observedRows = rows.filter((row) => row.reports_submitted > 0);

  if (observedRows.length < 2) return null;

  const peak = observedRows.reduce((currentPeak, row) =>
    row.work_items.total > currentPeak.work_items.total ? row : currentPeak,
  );

  return {
    observedDays: observedRows.length,
    totalDays: rows.length,
    peakDate: peak.date,
    peakWorkItems: peak.work_items.total,
    blockedItems: observedRows.reduce(
      (total, row) => total + row.work_items.blocked,
      0,
    ),
    blockedDays: observedRows.filter((row) => row.work_items.blocked > 0)
      .length,
  };
};
