import type {
  AnalysisEmployee,
  AnalysisSource,
  WorkReportAnalysis,
} from "./workReportAnalysis";

export interface PersonalWeeklyReport {
  period: WorkReportAnalysis["period"];
  status: AnalysisEmployee["status"];
  employee: AnalysisEmployee | null;
  source_reports: AnalysisSource[];
  source_changed: boolean;
  updating: boolean;
  captured_at: string | null;
  is_mock: boolean;
}
