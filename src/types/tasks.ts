export type TaskStatus = "TODO" | "IN_PROGRESS" | "BLOCKED" | "COMPLETED";

export type TaskPriority = "LOW" | "MEDIUM" | "HIGH" | "URGENT";

export type TaskReference = {
  id: number;
  name: string;
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
};

export type TaskFormValues = {
  title: string;
  description: string;
  assigneeId: string;
  priority: TaskPriority;
  startDate: string;
  dueDate: string;
};

export type TaskResponse = { data: ManagedTask };
export type TaskReferencesResponse = { data: TaskReferences };
