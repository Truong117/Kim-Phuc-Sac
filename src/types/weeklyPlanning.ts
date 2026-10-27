import type { TaskPriority } from "@/types/tasks";

export type WeeklyPlanStatus =
  | "DRAFT"
  | "SUBMITTED"
  | "IN_REVIEW"
  | "CHANGES_REQUESTED"
  | "APPROVED"
  | "PUBLISHED";

export type WeeklyPlanReviewStatus =
  "PENDING" | "CHANGES_REQUESTED" | "ACCEPTED";

export type WeeklyPlanEmployee = {
  id: number;
  name: string;
  email: string;
  department: { id: number; name: string } | null;
  location: { id: number; name: string } | null;
};

export type WeeklyPlanGoogleDocsTabEvidence = {
  type: "GOOGLE_DOCS_TAB";
  tab_id: string | null;
  parent_tab_id: string | null;
  nesting_level: number | null;
  index: number | null;
};

export type WeeklyPlanSourceEvidence =
  | WeeklyPlanGoogleDocsTabEvidence
  | {
      type: string | null;
      section?: number | null;
      start_sequence?: number | null;
      end_sequence?: number | null;
      start_source_key?: string | null;
      end_source_key?: string | null;
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
  source_passages: Array<
    Pick<WeeklyPlanSourcePassage, "id" | "source_key" | "kind" | "text">
  >;
};

export type WeeklyPlanSourcePassage = {
  id: number;
  source_key: string;
  sequence: number;
  kind: "heading" | "paragraph" | "list_item" | "table_row";
  text: string;
  metadata: Record<string, unknown>;
  source_context: {
    version: number;
    key: string;
    origin: "DOCX_SECTION" | "GOOGLE_DOCS_TAB";
    order: number;
    evidence: WeeklyPlanSourceEvidence;
  } | null;
  source_group: {
    version: number;
    key: string;
    title: string;
    origin: "MANUAL" | "GOOGLE_DOCS_TAB";
    order: number;
    identity_verified: false;
    evidence: WeeklyPlanSourceEvidence;
  } | null;
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
  can_extract_source: boolean;
  can_replace_source: boolean;
  can_apply_source: boolean;
  can_group_source: boolean;
  can_ai_extract_source: boolean;
  can_manage_google_docs_mappings: boolean;
};

export type WeeklyPlanGoogleDocsMappingStatus =
  | "UNVERIFIED"
  | "VERIFIED"
  | "NEEDS_REVIEW"
  | "NOT_APPLICABLE";

export type WeeklyPlanGoogleDocsEmployeeMappingTab = {
  tab_id: string;
  title: string;
  parent_tab_id: string | null;
  nesting_level: number;
  order: number;
  status: WeeklyPlanGoogleDocsMappingStatus;
  employee: WeeklyPlanEmployee | null;
  suggestion: {
    employee: WeeklyPlanEmployee;
    basis: "CONFIRMED_EXACT" | "CONFIRMED_ALIAS";
  } | null;
  version: number;
  verified_at: string | null;
  reason: "EMPLOYEE_INELIGIBLE" | null;
};

export type WeeklyPlanGoogleDocsEmployeeMappings = {
  tabs: WeeklyPlanGoogleDocsEmployeeMappingTab[];
  employees: WeeklyPlanEmployee[];
  actions: { can_update: boolean };
};

export type WeeklyPlan = {
  id: number;
  week_start: string;
  week_end: string;
  status: WeeklyPlanStatus;
  source_type: "UPLOADED_FILE" | "GOOGLE_DOCS";
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
    availability:
      | "AVAILABLE"
      | "MISSING"
      | "UNAVAILABLE"
      | "NOT_APPLICABLE"
      | null;
  };
  source_document: {
    status:
      | "NOT_EXTRACTED"
      | "EXTRACTED"
      | "EMPTY"
      | "MISSING"
      | "UNSUPPORTED"
      | "UNAVAILABLE";
    ai: {
      configured: boolean;
      provider: string;
    };
    limits: {
      max_passages_per_entry: number;
      max_entries_per_request: number;
    };
    google_docs: (Omit<WeeklyPlanGoogleDocsPreview, "revision_id"> & {
      captured_at: string;
      last_verified_at: string;
      stale_at: string | null;
    }) | null;
    passages: WeeklyPlanSourcePassage[];
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

export type WeeklyPlanGoogleDocsSourceGroup = {
  version: number;
  key: string;
  title: string;
  origin: "GOOGLE_DOCS_TAB";
  order: number;
  identity_verified: false;
  evidence: WeeklyPlanGoogleDocsTabEvidence;
};

export type WeeklyPlanGoogleDocsPreviewTab = {
  tab_id: string;
  title: string;
  parent_tab_id: string | null;
  nesting_level: number;
  order: number;
  passage_count: number;
  character_count: number;
  source_group: WeeklyPlanGoogleDocsSourceGroup;
};

export type WeeklyPlanGoogleDocsPreview = {
  title: string;
  revision_id: string | null;
  snapshot_sha256: string;
  identity_verified: false;
  tab_count: number;
  passage_count: number;
  character_count: number;
  tabs: WeeklyPlanGoogleDocsPreviewTab[];
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

export type WeeklyPlanSourceEntryInput = WeeklyPlanEntryInput & {
  source_passage_ids: number[];
};

export type WeeklyPlanAiSuggestion = {
  source_keys: string[];
  title: string;
  content: string | null;
  expected_result: string | null;
  employee_identifier: string | null;
  start_date: string | null;
  due_date: string | null;
  priority: TaskPriority | null;
  note: string | null;
  verification_fields: string[];
  evidence: string;
  provenance: Record<
    | "title"
    | "content"
    | "expected_result"
    | "employee_identifier"
    | "start_date"
    | "due_date"
    | "priority"
    | "note",
    "VERBATIM" | "INFERRED" | "SUGGESTED"
  >;
};
