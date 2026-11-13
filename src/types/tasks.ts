export type TaskStatus = "TODO" | "IN_PROGRESS" | "BLOCKED" | "COMPLETED";

export type TaskPriority = "LOW" | "MEDIUM" | "HIGH" | "URGENT";

export type TaskReference = {
  id: number;
  name: string;
};

export type TaskQuantityUnit =
  "video" | "ảnh" | "bài viết" | "sản phẩm" | "khách hàng" | "lượt chăm sóc";
export type TaskOutput = {
  id?: number | null;
  name: string;
  reference?: string | null;
  care_customer_id?: number | null;
  unit?: TaskQuantityUnit;
  completed: boolean;
  first_completed_on?: string | null;
};
export type TaskProgress = {
  revision: number;
  target: number | null;
  unit: TaskQuantityUnit | null;
  completed: number;
  counts: Record<string, number>;
  percent: number | null;
  outputs: TaskOutput[];
};
export type TaskProgressInput = {
  expected_revision: number;
  status: "KEEP" | Exclude<TaskStatus, "TODO">;
  unit: TaskQuantityUnit | null;
  outputs: TaskOutput[];
  care_entries?: CareReportEntry[];
};

export type CareActivity = { service: string; result: string };
export type CareReportEntry = {
  client_key: string;
  customer_id?: number;
  name?: string;
  new_customer?: {
    request_key: string;
    name: string;
    channel: string;
    contact?: string;
  };
  contact_update?: { channel: string; value: string };
  legacy_output_id?: number;
  activities: CareActivity[];
  evidence_links: string[];
  attachment_ids: number[];
  attachment_names?: Record<string, string>;
};
export type CareCustomer = {
  id: number;
  name: string;
  code: string;
  contacts: { channel: string; value: string | null }[];
  task_output_id: number | null;
  counted: boolean;
};
export type ReportTaskLink = {
  task_id: number;
  snapshot: {
    id: number;
    title: string;
    description: string | null;
    due_date: string;
    target_quantity?: number | null;
    quantity_unit?: TaskQuantityUnit | null;
  };
  progress: {
    input: TaskProgressInput;
    source_result?: string;
    applied?: boolean;
    outputs?: TaskOutput[];
  };
  pending?: boolean;
};

export type ManagedTask = {
  id: number;
  title: string;
  description: string | null;
  priority: TaskPriority;
  status: TaskStatus;
  start_date: string | null;
  due_date: string;
  is_overdue: boolean;
  completed_at: string | null;
  target_quantity?: number | null;
  quantity_unit?: TaskQuantityUnit | null;
  progress?: TaskProgress | null;
  creator: TaskReference;
  assignee: TaskReference;
  department: TaskReference | null;
  location: TaskReference | null;
  actions: {
    can_manage: boolean;
    can_update_status: boolean;
  };
  created_at: string;
  updated_at: string;
};

export type TaskSummary = {
  total: number;
  todo: number;
  in_progress: number;
  blocked: number;
  completed: number;
  overdue: number;
};

export type TaskListMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
};

export type TaskListResponse = {
  data: ManagedTask[];
  meta: TaskListMeta;
  summary: TaskSummary;
  actions: { can_create: boolean };
};

export type TaskFilterOptionGroup = {
  visible: boolean;
  options: TaskReference[];
};

export type AssignableEmployee = TaskReference & {
  department: TaskReference | null;
  location: TaskReference | null;
};

export type TaskReferences = {
  employees: TaskFilterOptionGroup;
  departments: TaskFilterOptionGroup;
  locations: TaskFilterOptionGroup;
  assignable_employees: AssignableEmployee[];
  actions: { can_create: boolean };
};

export type TaskListFilters = {
  search: string;
  status: "" | TaskStatus;
  priority: "" | TaskPriority;
  assigneeId: string;
  departmentId: string;
  locationId: string;
  dueFrom: string;
  dueTo: string;
  overdue: boolean;
};

export type TaskListQuery = TaskListFilters & {
  page: number;
  perPage: number;
};

export type TaskInput = {
  title: string;
  description: string | null;
  assignee_id: number;
  priority: TaskPriority;
  start_date: string | null;
  due_date: string;
  target_quantity?: number | null;
  quantity_unit?: TaskQuantityUnit | null;
};

export type TaskFormValues = {
  title: string;
  description: string;
  assigneeId: string;
  priority: TaskPriority;
  startDate: string;
  dueDate: string;
  targetQuantity?: string;
  quantityUnit?: TaskQuantityUnit | "";
};

export type TaskResponse = { data: ManagedTask };
export type TaskReferencesResponse = { data: TaskReferences };
