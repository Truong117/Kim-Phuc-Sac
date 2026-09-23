export type DashboardPeriod = "today" | "week" | "month";

export type DashboardStatKind =
  "total" | "completed" | "inProgress" | "blocked";

export interface DashboardStat {
  kind: DashboardStatKind;
  value: number;
  percentage?: number;
}

export interface WorkCategoryStat {
  category: string;
  percentage: number;
}

export interface EmployeePerformance {
  id: string;
  name: string;
  totalTasks: number;
  completedTasks: number;
  inProgressTasks: number;
  blockedTasks: number;
}

export interface AIManagementInsight {
  summary: string;
  highlights: string[];
}

export type ReportStatus = "COMPLETED" | "IN_PROGRESS" | "BLOCKED";

export interface RecentReport {
  id: string;
  reportDate: string;
  employeeName: string;
  department: string;
  totalTasks: number;
  completedTasks: number;
  status: ReportStatus;
}
