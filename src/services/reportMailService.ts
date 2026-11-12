import { apiRequest } from "./apiClient";

export type ReportMailEligibility =
  "placeholder" | "invalid_email" | "unverified" | "opted_out" | "ready";
export interface ReportMailStatus {
  schema_ready: boolean;
  email: string;
  eligibility: ReportMailEligibility;
  verified: boolean;
  weekly_enabled: boolean;
  auto_enabled: boolean;
  can_request: boolean;
  verification_status: string | null;
  request_after: string | null;
  verification_expires_at: string | null;
}
export interface ReportMailRoster {
  schema_ready: boolean;
  auto_enabled: boolean;
  start_date?: string | null;
  recipients: {
    user_id: number;
    name: string;
    email: string;
    department: string | null;
    eligibility: ReportMailEligibility;
    delivery_status: string;
    error_code: string | null;
  }[];
  total: number;
  page: number;
  last_page: number;
}
const root = "/api/account/report-mail";
const data = async <T>(path: string, init?: RequestInit): Promise<T> =>
  (await apiRequest<{ data: T }>(path, { cache: "no-store", ...init })).data;
export const getReportMailStatus = (signal?: AbortSignal) =>
  data<ReportMailStatus>(root, { signal });
export const requestReportEmailVerification = (
  requestKey: string,
  expectedEmail: string,
) =>
  data<ReportMailStatus>(root + "/verification", {
    method: "POST",
    body: JSON.stringify({
      request_key: requestKey,
      expected_email: expectedEmail,
    }),
  });
export const confirmReportEmail = (token: string) =>
  data<ReportMailStatus>(root + "/confirm", {
    method: "POST",
    body: JSON.stringify({ token }),
  });
export const updateReportMail = (enabled: boolean) =>
  data<ReportMailStatus>(root, {
    method: "PATCH",
    body: JSON.stringify({ weekly_enabled: enabled }),
  });
export const getReportMailRoster = (
  endDate: string,
  search: string,
  page: number,
  signal?: AbortSignal,
) =>
  data<ReportMailRoster>(
    `/api/integrations/mail/personal-weekly-recipients?${new URLSearchParams({ end_date: endDate, search, page: String(page) })}`,
    { signal },
  );
