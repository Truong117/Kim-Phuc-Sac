import type {
  FacebookAnalyticsPost,
  FacebookMetric,
  FacebookObservationInterval,
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
export function trendContributors(
  posts: FacebookAnalyticsPost[],
  date: string,
  metric: FacebookMetric,
): {
  post: FacebookAnalyticsPost;
  value: number;
  interval: FacebookObservationInterval | null;
}[] {
  return posts
    .flatMap((post) => {
      const point = post.trend.find((p) => p.date === date);
      const value = point?.[metric];
      return value == null
        ? []
        : [{ post, value, interval: point?.intervals?.[metric] ?? null }];
    })
    .sort(
      (a, b) => Math.abs(b.value) - Math.abs(a.value) || a.post.id - b.post.id,
    );
}
export function postMilestoneAt(publishedAt: string, days: 1 | 3 | 7): string {
  return new Date(
    new Date(publishedAt).getTime() + days * 24 * 60 * 60 * 1000,
  ).toISOString();
}
export function csvCell(value: string | number | null) {
  const raw = value == null ? "" : String(value);
  // Spreadsheet applications must not execute captions as formulas.
  const safe =
    /^[=+@-]/.test(raw.replace(/^[\s\p{Cc}]*/u, "")) &&
    typeof value === "string"
      ? "'" + raw
      : raw;
  return '"' + safe.replaceAll('"', '""') + '"';
}

// Facebook captions do not have a separate title field. Keep the first line;
// do not invent a summary or send employee content to an AI provider.
export function facebookPostTitle(message: string, limit = 100): string {
  const first =
    message
      .split(/\r?\n/)
      .map((line) => line.trim())
      .find(Boolean) ?? "";
  const title = first
    .split(
      /(?:[-–—_=]\s*){4,}|\b(?:hotline|website|youtube)\s*:|https?:\/\//iu,
      1,
    )[0]
    .replace(/\s+/gu, " ")
    .trim();
  const points = Array.from(title || first);
  if (points.length <= limit) return points.join("");
  const clipped = points.slice(0, Math.max(1, limit - 1)).join("");
  const boundary = clipped.lastIndexOf(" ");
  return (
    (boundary > limit * 0.65 ? clipped.slice(0, boundary) : clipped).trimEnd() +
    "…"
  );
}
