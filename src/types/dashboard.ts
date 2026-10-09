import type { ReportWorkItem, WorkStatus } from "@/types/reports";

export type DashboardMode =
  | "organization"
  | "department"
  | "location"
  | "personal";

export type DashboardEntity = { id: number; name: string };

export type DashboardReporting = {
  expected: number;
  submitted: number;
  missing: number;
};

export type DashboardWork = {
  total: number;
  completed: number;
  in_progress: number;
  blocked: number;
};

export type DashboardSelfReportState =
  | "not_submitted_open"
  | "submitted_editable"
  | "locked"
  | "not_submitted_closed";

export type DashboardSelfReport = {
  state: DashboardSelfReportState;
  report_id: number | null;
  report_date: string;
  cutoff_at: string;
  overall_status: WorkStatus | null;
  work: DashboardWork;
  items: ReportWorkItem[];
  unread_comment_count: number;
  actions: {
    can_create: boolean;
    can_edit: boolean;
    can_view: boolean;
  };
};

export type DashboardGroupBreakdown = {
  group: DashboardEntity | null;
  reporting: DashboardReporting;
  work: DashboardWork;
};

export type DashboardPersonProgress = {
  employee: DashboardEntity;
  report_state: "submitted" | "missing";
  report_id: number | null;
  work: DashboardWork | null;
};

export type DashboardAttentionItem = {
  item_id: number;
  report_id: number;
  content: string;
  employee: DashboardEntity;
  department: DashboardEntity | null;
  location: DashboardEntity | null;
};

export type DashboardRecentReport = {
  id: number;
  report_date: string;
  overall_status: WorkStatus;
  employee: DashboardEntity;
  department: DashboardEntity | null;
  location: DashboardEntity | null;
  work: DashboardWork;
};

export type DashboardData = {
  mode: DashboardMode;
  business_date: string;
  cutoff_at: string;
  context: DashboardEntity | null;
  reporting: DashboardReporting | null;
  work: DashboardWork;
  self_report: DashboardSelfReport | null;
  group_breakdown: DashboardGroupBreakdown[];
  people_progress: DashboardPersonProgress[];
  attention_items: DashboardAttentionItem[];
  recent_reports: DashboardRecentReport[];
};
