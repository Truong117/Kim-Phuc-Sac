export type WorkStatus = "COMPLETED" | "IN_PROGRESS" | "BLOCKED";

export type ReportOverallStatus = WorkStatus;

export type ReportEntity = { id: number; name: string };

export type ReportEmployee = ReportEntity & { email?: string };

export type ReportWorkItem = {
  id: number;
  content: string;
  result: string;
  status: WorkStatus;
  note: string | null;
  sort_order: number;
};

export type ReportComment = {
  id: number;
  author: ReportEntity;
  content: string;
  created_at: string;
};

export type DailyReport = {
  id: number;
  report_date: string;
  locked_at: string;
  is_locked: boolean;
  overall_status: ReportOverallStatus;
  employee: ReportEmployee;
  department: ReportEntity | null;
  location: ReportEntity | null;
  items: ReportWorkItem[];
  comments: ReportComment[];
  comment_count: number;
  unread_comment_count: number;
  actions: { can_edit: boolean; can_comment: boolean };
  created_at: string | null;
  updated_at: string | null;
};

export type ReportListItem = {
  id: number;
  report_date: string;
  locked_at: string;
  is_locked: boolean;
  overall_status: ReportOverallStatus;
  employee: ReportEntity;
  department: ReportEntity | null;
  location: ReportEntity | null;
  counts: {
    items: number;
    completed: number;
    in_progress: number;
    blocked: number;
    comments: number;
    unread_comments: number;
  };
  created_at: string | null;
  updated_at: string | null;
};

export type PaginationMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
};

export type ReportListResponse = {
  data: ReportListItem[];
  meta: PaginationMeta;
};

export type TodayReportState = {
  business_date: string;
  cutoff_at: string;
  is_window_closed: boolean;
  reporting_required: boolean;
  report: DailyReport | null;
  actions: { can_create: boolean };
};

export type ReportReferenceGroup = {
  visible: boolean;
  options: ReportEntity[];
};

export type ReportReferences = {
  employees: ReportReferenceGroup;
  departments: ReportReferenceGroup;
  locations: ReportReferenceGroup;
};

export type WorkItemInput = {
  content: string;
  result: string;
  status: WorkStatus;
  note: string | null;
};

export type SaveReportInput = { items: WorkItemInput[] };

export type WorkItemFormData = {
  clientId: string;
  content: string;
  result: string;
  status: WorkStatus | "";
  note: string;
};

export type WorkItemValidationErrors = {
  content?: string;
  result?: string;
  status?: string;
};

export type WorkItemField = keyof WorkItemValidationErrors;
export type WorkItemTouchedFields = Partial<Record<WorkItemField, boolean>>;

export type ReportHistoryFilterValues = {
  fromDate: string;
  toDate: string;
  employeeId: string;
  departmentId: string;
  locationId: string;
  status: ReportOverallStatus | "";
  search: string;
};

export type ReportListQuery = ReportHistoryFilterValues & {
  page: number;
  perPage: number;
};

export type MarkReportNotificationsReadResponse = {
  data: { report_id: number; marked_read_count: number };
};
