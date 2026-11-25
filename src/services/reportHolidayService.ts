import { apiRequest } from "@/services/apiClient";
import type {
  ReportHoliday,
  ReportHolidayInput,
  ReportHolidaysResponse,
} from "@/types/reportHolidays";

const endpoint = "/api/settings/report-holidays";

export const getReportHolidays = (signal?: AbortSignal) =>
  apiRequest<ReportHolidaysResponse>(endpoint, { signal });

export const createReportHoliday = async (input: ReportHolidayInput) =>
  (
    await apiRequest<{ data: ReportHoliday }>(endpoint, {
      method: "POST",
      body: JSON.stringify(input),
    })
  ).data;

export const updateReportHoliday = async (
  holiday: ReportHoliday,
  input: Partial<ReportHolidayInput> & { is_active?: boolean },
) =>
  (
    await apiRequest<{ data: ReportHoliday }>(`${endpoint}/${holiday.id}`, {
      method: "PATCH",
      body: JSON.stringify({ ...input, expected_version: holiday.version }),
    })
  ).data;

export const deleteReportHoliday = (holiday: ReportHoliday) =>
  apiRequest<void>(`${endpoint}/${holiday.id}`, {
    method: "DELETE",
    body: JSON.stringify({ expected_version: holiday.version }),
  });
