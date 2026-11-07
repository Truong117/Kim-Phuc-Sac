import { apiRequest } from "./apiClient";
import type {
  FacebookAnalyticsData,
  FacebookAnalyticsPage,
  FacebookTopic,
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
