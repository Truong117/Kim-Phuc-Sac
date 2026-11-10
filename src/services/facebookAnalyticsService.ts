import { apiRequest } from "./apiClient";
import type {
  FacebookAnalyticsData,
  FacebookAnalyticsPage,
  FacebookTopic,
  FacebookAnalyticsPost,
  FacebookMilestoneData,
  FacebookObjective,
  FacebookDistribution,
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
