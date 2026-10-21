export type AiDailyReportStatus =
  "pending" | "running" | "completed" | "failed";
export type AiEvidence = { text: string; report_ids: number[] };
export type AiDailyAnalysis = {
  overview: string;
  highlights: AiEvidence[];
  attention: AiEvidence[];
  recommendations: AiEvidence[];
};
export type AiWorkCounts = {
  reports: number;
  items: number;
  completed: number;
  in_progress: number;
  blocked: number;
};
export type AiDailySummary = {
  report_date: string;
  work_counts: AiWorkCounts;
  source_report_ids: number[];
};
export type AiDailyAvailableDates = {
  dates: string[];
};
export type AiDailySourceReport = {
  id: number;
  employee_name: string;
  department_name: string | null;
};
export type AiDailyReport = {
  id: number;
  report_date: string;
  status: AiDailyReportStatus;
  work_counts: AiWorkCounts | null;
  source_report_ids: number[];
  source_reports: AiDailySourceReport[];
  result: AiDailyAnalysis | null;
  provider: string | null;
  is_mock: boolean;
  completed_at: string | null;
  error_code: string | null;
};
