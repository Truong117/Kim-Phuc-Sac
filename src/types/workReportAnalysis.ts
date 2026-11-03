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
export interface GroupedWork {
  title: string;
  text: string;
  evidence: { report_id: number; item_id: number; quote: string }[];
}
export interface WeeklyWorkGroup {
  content: string;
  observations: {
    report_id: number;
    item_id: number;
    date: string;
    status: "COMPLETED" | "IN_PROGRESS" | "BLOCKED";
    result: string;
    note: string | null;
  }[];
}
export interface WorkTotal {
  metric: string;
  unit: string;
  total: number | null;
  status: "confirmed" | "needs_confirmation";
  entries: {
    report_id: number;
    item_id: number;
    quote: string;
    quantity: number;
    basis: "incremental" | "cumulative" | "unclear";
  }[];
}
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
  work_groups?: GroupedWork[];
  work_inventory?: WeeklyWorkGroup[];
  work_totals?: WorkTotal[];
}
export interface DailyCoverage {
  mode: string; ready_reports: number; total_reports: number; missing_report_ids: number[];
}
export interface WorkReportAnalysis {
  daily_coverage?: DailyCoverage | null;
  id: number;
  status: "pending" | "running" | "completed" | "partial" | "failed";
  period: {
    start_date: string;
    end_date: string;
    days: number;
    timezone: string;
  };
  department_id: number | null;
  scope: {
    organization_id: number;
    restrictions: [
      "organization" | "department" | "location" | "user",
      number | null,
    ][];
  };
  source_changed: boolean | null;
  config_changed: boolean;
  requires_new_run: boolean;
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
  can_regenerate: boolean;
  can_regenerate_summary?: boolean;
  summary_parts?: number;
  reused_employee_units?: number;
  created_at: string;
  requested_by_name: string | null;
  revision: { number: number; root_id: number; parent_id: number | null };
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
  daily_coverage?: DailyCoverage | null;
  enabled: boolean;
  mode: string;
  departments: { id: number; name: string }[];
  max_days_back: number;
  work_counts: AiWorkCounts;
  employees: (Pick<
    AnalysisEmployee,
    "employee_id" | "name" | "department_name"
  > & { work_inventory?: WeeklyWorkGroup[] })[];
  source_reports: AnalysisSource[];
  run: WorkReportAnalysis | null;
  latest_attempt: { id: number; status: WorkReportAnalysis["status"] } | null;
}
export interface AnalysisRegeneration {
  data: WorkReportAnalysis;
  operation: {
    key: string;
    source_analysis_id: number;
    reused_existing: boolean;
  };
}
