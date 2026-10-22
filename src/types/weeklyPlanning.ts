import type { TaskPriority } from "@/types/tasks";

export type WeeklyPlanStatus =
  | "DRAFT"
  | "SUBMITTED"
  | "IN_REVIEW"
  | "CHANGES_REQUESTED"
  | "APPROVED"
  | "PUBLISHED";

export type WeeklyPlanReviewStatus =
  | "PENDING"
  | "CHANGES_REQUESTED"
  | "ACCEPTED";

export type WeeklyPlanEmployee = {
  id: number;
  name: string;
  email: string;
  department: { id: number; name: string } | null;
  location: { id: number; name: string } | null;
};

export type WeeklyPlanEntry = {
  id: number;
  source_row: number | null;
  employee_identifier: string | null;
  employee_name: string | null;
  department_name: string | null;
  assignee: Pick<WeeklyPlanEmployee, "id" | "name" | "email"> | null;
  title: string;
  content: string | null;
  expected_result: string | null;
  start_date: string | null;
  due_date: string | null;
  priority: TaskPriority | null;
  note: string | null;
  sort_order: number;
  validation_status: "VALID" | "INVALID";
  validation_errors: string[];
};

export type WeeklyPlanReview = {
  id: number;
  assignee: { id: number; name: string };
  status: WeeklyPlanReviewStatus;
  comment: string | null;
  reviewed_at: string | null;
};

export type WeeklyPlanSuggestion = {
  id: number;
  entry_id: number;
  type: string;
  original_value: string | null;
  suggested_value: string | null;
  reason: string;
  status: "PENDING" | "ACCEPTED" | "REJECTED";
};

export type WeeklyPlanComment = {
  id: number;
  entry_id: number | null;
  assignee_id: number | null;
  author: { id: number; name: string };
  content: string;
  created_at: string;
};

export type WeeklyPlanEvent = {
  id: number;
  type: string;
  actor: { id: number; name: string } | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
};

export type WeeklyPlanActions = {
  can_edit: boolean;
  can_analyze: boolean;
  can_submit: boolean;
  can_review: boolean;
  can_review_edit: boolean;
  can_approve: boolean;
  can_publish: boolean;
  can_approve_and_publish: boolean;
  can_comment: boolean;
};

export type WeeklyPlan = {
  id: number;
  week_start: string;
  week_end: string;
  status: WeeklyPlanStatus;
  parse_status: "PARSED" | "NEEDS_MAPPING" | "FAILED";
  parse_metadata: {
    headers?: string[];
    errors?: string[];
    row_count?: number;
    mapping?: Record<string, string>;
  } | null;
  source_file: {
    name: string;
    extension: string;
    mime: string;
    size: number;
    sha256: string;
    uploaded_at: string | null;
  };
  creator: { id: number; name: string };
  entries_count: number | null;
  summary: {
    employee_count: number;
    matched_employee_count: number;
    entry_count: number;
    invalid_entry_count: number;
    departments: string[];
  };
  entries: WeeklyPlanEntry[];
  employee_reviews: WeeklyPlanReview[];
  suggestions: WeeklyPlanSuggestion[];
  comments: WeeklyPlanComment[];
  events: WeeklyPlanEvent[];
  submitted_at: string | null;
  approved_at: string | null;
  published_at: string | null;
  actions: WeeklyPlanActions;
};

export type WeeklyPlanListItem = Pick<
  WeeklyPlan,
  | "id"
  | "week_start"
  | "week_end"
  | "status"
  | "parse_status"
  | "source_file"
  | "creator"
  | "entries_count"
>;

export type WeeklyPlanListResponse = {
  data: WeeklyPlanListItem[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type WeeklyPlanReferences = {
  next_week: { start: string; end: string };
  employees: WeeklyPlanEmployee[];
  actions: { can_import: boolean };
};

export type WeeklyPlanEntryInput = {
  assignee_id: number;
  title: string;
  content: string | null;
  expected_result: string | null;
  start_date: string;
  due_date: string;
  priority: TaskPriority;
  note: string | null;
};
