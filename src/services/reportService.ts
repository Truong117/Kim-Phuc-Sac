import { apiRequest } from "@/services/apiClient";
import type {
  DailyReport,
  MarkReportNotificationsReadResponse,
  ReportComment,
  ReportListQuery,
  ReportListResponse,
  ReportReferences,
  SaveReportInput,
  TodayReportState,
} from "@/types/reports";

type DataResponse<T> = { data: T };

const buildReportQuery = (query: ReportListQuery) => {
  const parameters = new URLSearchParams({
    page: String(query.page),
    per_page: String(query.perPage),
  });

  if (query.fromDate) parameters.set("from_date", query.fromDate);
  if (query.toDate) parameters.set("to_date", query.toDate);
  if (query.employeeId) parameters.set("user_id", query.employeeId);
  if (query.departmentId) {
    parameters.set("department_id", query.departmentId);
  }
  if (query.locationId) parameters.set("location_id", query.locationId);
  if (query.status) parameters.set("status", query.status);

  const search = query.search.trim();
  if (search) parameters.set("search", search);

  return parameters.toString();
};

export const getTodayReport = async (
  signal?: AbortSignal,
): Promise<TodayReportState> => {
  const response = await apiRequest<DataResponse<TodayReportState>>(
    "/api/reports/today",
    { signal },
  );
  return response.data;
};

export const getReportReferences = async (
  signal?: AbortSignal,
): Promise<ReportReferences> => {
  const response = await apiRequest<DataResponse<ReportReferences>>(
    "/api/reports/references",
    { signal },
  );
  return response.data;
};

export const getReports = (
  query: ReportListQuery,
  signal?: AbortSignal,
): Promise<ReportListResponse> =>
  apiRequest<ReportListResponse>(`/api/reports?${buildReportQuery(query)}`, {
    signal,
  });

export const createReport = async (
  input: SaveReportInput,
): Promise<DailyReport> => {
  const response = await apiRequest<DataResponse<DailyReport>>("/api/reports", {
    method: "POST",
    body: JSON.stringify(input),
  });
  return response.data;
};

export const updateReport = async (
  reportId: number,
  input: SaveReportInput,
): Promise<DailyReport> => {
  const response = await apiRequest<DataResponse<DailyReport>>(
    `/api/reports/${reportId}`,
    { method: "PATCH", body: JSON.stringify(input) },
  );
  return response.data;
};

export const getReport = async (
  reportId: string | number,
  signal?: AbortSignal,
): Promise<DailyReport> => {
  const response = await apiRequest<DataResponse<DailyReport>>(
    `/api/reports/${encodeURIComponent(String(reportId))}`,
    { signal },
  );
  return response.data;
};

export const addReportComment = async (
  reportId: number,
  content: string,
): Promise<ReportComment> => {
  const response = await apiRequest<DataResponse<ReportComment>>(
    `/api/reports/${reportId}/comments`,
    { method: "POST", body: JSON.stringify({ content }) },
  );
  return response.data;
};

export const markReportNotificationsRead = (
  reportId: number,
): Promise<MarkReportNotificationsReadResponse> =>
  apiRequest<MarkReportNotificationsReadResponse>(
    `/api/reports/${reportId}/notifications/read`,
    { method: "PATCH" },
  );
