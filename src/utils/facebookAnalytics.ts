import type {
  FacebookAnalyticsPost,
  FacebookMetric,
  FacebookTrendPoint,
} from "@/types/facebookAnalytics";
import { addDaysToDateOnly, dateOnlyInTimeZone } from "./dateOnly";
export const facebookMetricKeys: FacebookMetric[] = [
  "likes",
  "comments",
  "media_views",
];
export const facebookTopics = [
  "education",
  "product",
  "company",
  "recruitment",
  "other",
] as const;
export const analyticsNumber = (value: number | null | undefined) =>
  value == null ? "—" : new Intl.NumberFormat("vi-VN").format(value);
export const observationDate = (value: string | null) =>
  value
    ? new Intl.DateTimeFormat("vi-VN", {
        dateStyle: "short",
        timeStyle: "short",
        timeZone: "Asia/Ho_Chi_Minh",
      }).format(
        new Date(
          /Z$|[+-]\d\d:\d\d$/.test(value)
            ? value
            : value.replace(" ", "T") + "Z",
        ),
      )
    : "—";
export const postInPeriod = (
  post: FacebookAnalyticsPost,
  from: string,
  to: string,
) => {
  const date = dateOnlyInTimeZone(new Date(post.published_at));
  return date >= from && date <= to;
};
export const interactionGrowth = (post: FacebookAnalyticsPost) =>
  post.observed_delta.likes == null || post.observed_delta.comments == null
    ? null
    : post.observed_delta.likes + post.observed_delta.comments;
export function analyticsSeries(
  posts: FacebookAnalyticsPost[],
  from: string,
  to: string,
): FacebookTrendPoint[] {
  const map = new Map<string, FacebookTrendPoint>();
  for (let d = from; d <= to && map.size < 90; d = addDaysToDateOnly(d, 1))
    map.set(d, { date: d, likes: null, comments: null, media_views: null });
  for (const post of posts)
    for (const point of post.trend) {
      const row = map.get(point.date);
      if (!row) continue;
      for (const key of facebookMetricKeys)
        if (point[key] != null) row[key] = (row[key] ?? 0) + point[key];
    }
  return [...map.values()];
}
export function csvCell(value: string | number | null) {
  const raw = value == null ? "" : String(value);
  // Spreadsheet applications must not execute captions as formulas.
  const safe =
    /^[=+@-]/.test(raw.trimStart()) && typeof value === "string"
      ? "'" + raw
      : raw;
  return '"' + safe.replaceAll('"', '""') + '"';
}
