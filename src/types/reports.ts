export type WorkStatus = "COMPLETED" | "IN_PROGRESS" | "BLOCKED";

export type ReportOverallStatus = WorkStatus;

export type ProcessingStage = "saving" | "analyzing" | "completed";

export interface WorkItemFormData {
  id: string;
  content: string;
  result: string;
  status: WorkStatus | "";
  customerId?: string;
  productId?: string;
  note?: string;
}

export interface DailyReportFormData {
  employeeId: string;
  employeeName: string;
  department: string;
  reportDate: string;
  workItems: WorkItemFormData[];
}

export interface WorkItemValidationErrors {
  content?: string;
  result?: string;
  status?: string;
}

export type WorkItemField = keyof WorkItemValidationErrors;

export type WorkItemTouchedFields = Partial<Record<WorkItemField, boolean>>;

export interface ReportOption {
  id: string;
  name: string;
}

export interface ReportHistoryItem {
  id: string;
  reportDate: string;
  employeeId: string;
  employeeName: string;
  department: string;
  totalWorkItems: number;
  completedWorkItems: number;
  inProgressWorkItems: number;
  blockedWorkItems: number;
  primaryCategory?: string;
  status: ReportOverallStatus;
  createdAt: string;
}

export interface ReportHistoryFilterValues {
  fromDate: string;
  toDate: string;
  employeeId: string;
  department: string;
  status: ReportOverallStatus | "";
  search: string;
}
