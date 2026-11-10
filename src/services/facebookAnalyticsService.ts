import type {
  FacebookMonthlyReportData,
  FacebookMonthlyCloseInput,
} from "@/types/facebookAnalytics";
import { apiRequest } from "./apiClient";
import type {
  FacebookAnalyticsData,
  FacebookAnalyticsPage,
  FacebookTopic,
  FacebookAnalyticsPost,
  FacebookMilestoneData,
  FacebookObjective,
  FacebookDistribution,
  FacebookPeriodReviewData,
  FacebookReviewAction,
  FacebookMetric,
} from "@/types/facebookAnalytics";
const root = "/api/social-analytics/facebook";
export const getFacebookAnalyticsPages = (signal?: AbortSignal) =>
  apiRequest<{ data: FacebookAnalyticsPage[]; can_manage: boolean }>(
    `${root}/pages`,
    { signal },
  );
export const getFacebookAnalytics = (
  id: number,
  from: string,
  to: string,
  signal?: AbortSignal,
) =>
  apiRequest<{ data: FacebookAnalyticsData }>(
    `${root}/${id}?${new URLSearchParams({ from_date: from, to_date: to })}`,
    { signal },
  );
export const syncFacebookAnalytics = (id: number, signal?: AbortSignal) =>
  apiRequest(`${root}/${id}/sync`, { method: "POST", signal });
export const analyzeFacebook = (
  id: number,
  from: string,
  to: string,
  signal?: AbortSignal,
) =>
  apiRequest(`${root}/${id}/analyses`, {
    method: "POST",
    body: JSON.stringify({ from_date: from, to_date: to }),
    signal,
  });
export const saveFacebookTopic = (
  id: number,
  post: number,
  topic: FacebookTopic | null,
  signal?: AbortSignal,
) =>
  apiRequest(`${root}/${id}/posts/${post}/topic`, {
    method: "PATCH",
    body: JSON.stringify({ topic }),
    signal,
  });

export const getFacebookMilestones = (
  id: number,
  from: string,
  to: string,
  basis: "matured" | "published",
  signal?: AbortSignal,
) =>
  apiRequest<{ data: FacebookMilestoneData }>(
    `${root}/${id}/milestones?${new URLSearchParams({ from_date: from, to_date: to, basis })}`,
    { signal },
  );
export const getFacebookAnalyticsPost = (
  id: number,
  post: number,
  from: string,
  to: string,
  signal?: AbortSignal,
) =>
  apiRequest<{ data: FacebookAnalyticsPost }>(
    `${root}/${id}/posts/${post}?${new URLSearchParams({ from_date: from, to_date: to })}`,
    { signal },
  );
export const saveFacebookClassification = (
  id: number,
  post: number,
  values: {
    objective?: FacebookObjective | null;
    distribution?: FacebookDistribution | null;
  },
  signal?: AbortSignal,
) =>
  apiRequest<{
    data: {
      objective: FacebookObjective | null;
      distribution: FacebookDistribution | null;
    };
  }>(`${root}/${id}/posts/${post}/classification`, {
    method: "PATCH",
    body: JSON.stringify(values),
    signal,
  });

export const getFacebookPeriodReview = (
  id: number,
  from: string,
  to: string,
  metric: FacebookMetric,
  signal?: AbortSignal,
) =>
  apiRequest<{ data: FacebookPeriodReviewData }>(
    `${root}/${id}/review?${new URLSearchParams({ from_date: from, to_date: to, metric })}`,
    { signal },
  );
export const saveFacebookReviewPlan = (
  id: number,
  values: {
    from_date: string;
    to_date: string;
    metric: FacebookMetric;
    entry_version: number;
    version: number;
    source_hash: string;
    expected_posts: number | null;
    actions: FacebookReviewAction[];
  },
  signal?: AbortSignal,
) =>
  apiRequest<{ data: FacebookPeriodReviewData }>(`${root}/${id}/review`, {
    method: "PUT",
    body: JSON.stringify(values),
    signal,
  });

export const getFacebookMonthlyReport = (
  id: number,
  month: string,
  live: boolean,
  signal?: AbortSignal,
) =>
  apiRequest<{ data: FacebookMonthlyReportData }>(
    `${root}/${id}/monthly-report?${new URLSearchParams({ month, view: live ? "live" : "saved" })}`,
    { signal },
  );
export const closeFacebookMonthlyReport = (
  id: number,
  values: FacebookMonthlyCloseInput,
  signal?: AbortSignal,
) =>
  apiRequest<{ data: FacebookMonthlyReportData }>(
    `${root}/${id}/monthly-report`,
    { method: "POST", body: JSON.stringify(values), signal },
  );
