import type { AiWorkCounts } from "./aiDailyReport";

export const analysisSections = [
  "attention",
  "recommendations",
  "overview",
  "highlights",
  "ongoing",
  "support",
  "uncertainties",
] as const;
export type AnalysisSection = (typeof analysisSections)[number];
export type AnalysisResult = Record<AnalysisSection, AnalysisClaim[]>;
export interface AnalysisClaim {
  text: string;
  evidence: { report_id: number; item_id: number; quote: string }[];
}
export interface AnalysisSource {
  id: number;
  employee_id: number;
  employee_name: string;
  department_name: string | null;
  date: string;
  item_ids: number[];
}
export interface AnalysisEmployee {
  employee_id: number;
  name: string;
  department_name: string | null;
  status:
    "no_data" | "not_analyzed" | "running" | "completed" | "partial" | "failed";
  completed_units: number;
  total_units: number;
  failed_units: number;
  source_report_ids: number[];
  result: AnalysisResult | null;
}
export interface WorkReportAnalysis {
  id: number;
  status: "pending" | "running" | "completed" | "partial" | "failed";
  period: {
    start_date: string;
    end_date: string;
    days: number;
    timezone: string;
  };
  department_id: number | null;
  source_changed: boolean | null;
  config_changed: boolean;
  captured_at: string;
  completed_at: string | null;
  provider: string;
  model: string;
  is_mock: boolean;
  work_counts: AiWorkCounts;
  progress: { finished: number; total: number };
  result: AnalysisResult | null;
  employees: AnalysisEmployee[];
  source_reports: AnalysisSource[];
  errors: { unit_key: string; code: string }[];
  can_retry: boolean;
  usage: {
    input_tokens: number | null;
    output_tokens: number | null;
    cost_microusd: number | null;
  };
}
export interface AnalysisFilters {
  end_date: string;
  days: number;
  department_id: number | null;
}
export interface AnalysisPreview {
  enabled: boolean;
  mode: string;
  departments: { id: number; name: string }[];
  max_days_back: number;
  work_counts: AiWorkCounts;
  employees: Pick<
    AnalysisEmployee,
    "employee_id" | "name" | "department_name"
  >[];
  source_reports: AnalysisSource[];
  run: WorkReportAnalysis | null;
}
