export type AiExecutivePeriodKey = "7d" | "30d";

export type AiExecutiveDataStatus = "available" | "insufficient_data";

export type AiExecutiveWorkCounts = {
  total: number;
  completed: number;
  in_progress: number;
  blocked: number;
};

export type AiExecutivePeriod = {
  key: AiExecutivePeriodKey;
  days: number;
  start_date: string;
  end_date: string;
  timezone: string;
  generated_at: string;
};

export type AiExecutiveReporting = {
  status: AiExecutiveDataStatus;
  expected_reporters: number;
  working_days: number;
  expected_submissions: number;
  submitted: number;
  missing: number;
  rate_percent: number | null;
  basis: string;
};

export type AiExecutiveDailyTrend = {
  date: string;
  is_working_day: boolean;
  reports_submitted: number;
  work_items: AiExecutiveWorkCounts;
  reporting: {
    expected: number;
    submitted: number;
    rate_percent: number | null;
  };
};

export type AiExecutiveDepartment = {
  department: { id: number; name: string } | null;
  reports_submitted: number;
  work_items: AiExecutiveWorkCounts;
};

export type AiExecutiveAttentionItem = {
  item_id: number;
  report_id: number;
  report_date: string;
  content: string;
  note: string | null;
  employee_name: string;
  department: { id: number; name: string } | null;
};

export type AiExecutiveObservation = {
  code: string;
  message: string;
  source_report_ids: number[];
};

export type AiExecutiveDashboardData = {
  period: AiExecutivePeriod;
  data_status: AiExecutiveDataStatus;
  totals: {
    reports_submitted: number;
    work_items: AiExecutiveWorkCounts;
    blocked_items: number;
  };
  reporting: AiExecutiveReporting;
  daily_trend: AiExecutiveDailyTrend[];
  departments: AiExecutiveDepartment[];
  attention_items: AiExecutiveAttentionItem[];
  analysis: {
    status: AiExecutiveDataStatus;
    method: "rule_based_v1";
    observations: AiExecutiveObservation[];
    disclaimer: string;
  };
};
