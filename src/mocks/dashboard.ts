import type {
  AIManagementInsight,
  DashboardStat,
  EmployeePerformance,
  RecentReport,
  WorkCategoryStat,
} from "@/types/dashboard";

export const dashboardStats: DashboardStat[] = [
  { kind: "total", value: 48 },
  { kind: "completed", value: 39, percentage: 81 },
  { kind: "inProgress", value: 7, percentage: 15 },
  { kind: "blocked", value: 2, percentage: 4 },
];

export const workCategoryStats: WorkCategoryStat[] = [
  { category: "Tư vấn / Bán hàng", count: 17 },
  { category: "Chăm sóc khách hàng", count: 12 },
  { category: "Báo cáo / Dữ liệu", count: 10 },
  { category: "Họp / Phối hợp", count: 7 },
  { category: "Khác", count: 2 },
];

export const employeePerformance: EmployeePerformance[] = [
  {
    id: "employee-1",
    name: "Nguyễn Văn A",
    totalTasks: 8,
    completedTasks: 7,
    inProgressTasks: 1,
    blockedTasks: 0,
  },
  {
    id: "employee-2",
    name: "Trần Thị B",
    totalTasks: 10,
    completedTasks: 8,
    inProgressTasks: 1,
    blockedTasks: 1,
  },
  {
    id: "employee-3",
    name: "Lê Văn C",
    totalTasks: 7,
    completedTasks: 6,
    inProgressTasks: 1,
    blockedTasks: 0,
  },
  {
    id: "employee-4",
    name: "Phạm Thị D",
    totalTasks: 9,
    completedTasks: 7,
    inProgressTasks: 1,
    blockedTasks: 1,
  },
];

export const aiManagementInsight: AIManagementInsight = {
  summary:
    "Hôm nay hệ thống ghi nhận 48 công việc. 39 công việc đã hoàn thành. Hoạt động tập trung chủ yếu vào tư vấn bán hàng và chăm sóc khách hàng. Có 2 công việc đang gặp vấn đề và cần quản lý theo dõi.",
  highlights: [
    "2 công việc đang gặp vướng mắc.",
    "6 khách hàng cần tiếp tục theo dõi.",
    "Nhóm chăm sóc khách hàng còn 3 công việc chưa hoàn thành.",
    "Tư vấn / bán hàng là nhóm công việc chiếm tỷ trọng lớn nhất hôm nay.",
  ],
};

export const recentReports: RecentReport[] = [
  {
    id: "report-20260923-01",
    reportDate: "23/09/2026",
    employeeName: "Nguyễn Văn A",
    department: "Kinh doanh",
    totalTasks: 8,
    completedTasks: 7,
    status: "IN_PROGRESS",
  },
  {
    id: "report-20260923-02",
    reportDate: "23/09/2026",
    employeeName: "Trần Thị B",
    department: "CSKH",
    totalTasks: 10,
    completedTasks: 8,
    status: "BLOCKED",
  },
  {
    id: "report-20260922-01",
    reportDate: "22/09/2026",
    employeeName: "Lê Văn C",
    department: "Marketing",
    totalTasks: 7,
    completedTasks: 7,
    status: "COMPLETED",
  },
  {
    id: "report-20260922-02",
    reportDate: "22/09/2026",
    employeeName: "Phạm Thị D",
    department: "Vận hành",
    totalTasks: 9,
    completedTasks: 7,
    status: "BLOCKED",
  },
  {
    id: "report-20260921-01",
    reportDate: "21/09/2026",
    employeeName: "Đỗ Minh E",
    department: "Kinh doanh",
    totalTasks: 8,
    completedTasks: 8,
    status: "COMPLETED",
  },
];
