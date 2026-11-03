export interface DailySourceItem {
  item_id: number;
  status: "COMPLETED" | "IN_PROGRESS" | "BLOCKED";
  content: string;
  result: string;
  note: string;
  issue: string;
  evidence_links: string[];
  evidence_status: "unverified";
}
export interface DailySource {
  report_id: number;
  employee_id: number;
  date: string;
  is_makeup: boolean;
  submitted_at: string;
  next_day_plan: string[];
  items: DailySourceItem[];
}
export interface DailyDocument {
  id: number;
  report_id: number;
  status: "pending" | "running" | "completed" | "partial" | "failed";
  source: DailySource;
  mode: string;
  model: string | null;
  captured_at: string;
  completed_units: number;
  total_units: number;
  result: null | {
    comments: { item_id: number; text: string; quote: string }[];
    recommendations: { item_id: number; text: string; quote: string }[];
    work_totals: {
      metric: string;
      unit: string;
      total: number | null;
      status: string;
    }[];
  };
  units: {
    id: number;
    part: number;
    status: string;
    error_code: string | null;
    can_retry: boolean;
  }[];
}
export interface DailyDocumentsPage {
  enabled: boolean;
  date: string;
  page: number;
  last_page: number;
  total: number;
  reports: {
    report_id: number;
    employee_name: string;
    source: DailySource;
    document: DailyDocument | null;
    source_changed: boolean;
  }[];
}
