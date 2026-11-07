export type FacebookMetric = "likes" | "comments" | "media_views";
export type FacebookMetricValues = Record<FacebookMetric, number | null>;
export type FacebookTopic =
  "education" | "product" | "company" | "recruitment" | "other";
export interface FacebookAnalyticsPage {
  id: number;
  name: string;
  responsible_name: string | null;
  status: string;
}
export interface FacebookTrendPoint extends FacebookMetricValues {
  date: string;
}
export interface FacebookAnalyticsPost {
  id: number;
  external_id: string;
  url: string;
  message: string;
  picture: string | null;
  type: "reel" | "video" | "photo" | "post";
  topic: FacebookTopic | null;
  published_at: string;
  report_ids: number[];
  definition: string | null;
  sample_count: number;
  observed_from: string | null;
  observed_to: string | null;
  current: FacebookMetricValues;
  observed_delta: FacebookMetricValues;
  trend: FacebookTrendPoint[];
}
export interface FacebookAnalyticsAnalysis {
  id: string;
  status: string;
  error_code: string | null;
  result: {
    insights: {
      kind: "highlight" | "limit" | "recommendation";
      text: string;
      post_ids: number[];
    }[];
  } | null;
  created_at: string;
  finished_at: string | null;
  stale: boolean;
  interrupted: boolean;
  selected_posts: number;
  candidate_posts: number;
}
export interface FacebookAnalyticsData {
  entry_id: number;
  page_name: string;
  from_date: string;
  to_date: string;
  connection_status: string;
  principal_available: boolean;
  can_manage: boolean;
  ai_enabled: boolean;
  sync: {
    status: string;
    interrupted: boolean;
    sampled_at: string | null;
    catalogued_at: string | null;
    catalogue_complete: boolean;
    catalogue_from: string | null;
    catalogue_to: string | null;
    error_code: string | null;
    pending_samples: number;
    catalogue_posts: number;
    progress: {
      phase: "catalogue" | "samples" | "completed" | "failed";
      catalogue_posts: number;
      ready_posts: number;
      pending_posts: number;
      batches_completed: number;
      from_date: string;
      to_date: string;
      started_at: string;
      finished_at: string | null;
    } | null;
  };
  summary: {
    published: number;
    previous_published: number | null;
    observed_posts: number;
    growth: Record<
      FacebookMetric,
      {
        value: number | null;
        posts: number;
        comparison: {
          current: number;
          previous: number;
          posts: number;
          percent: number | null;
        } | null;
      }
    >;
  };
  posts: FacebookAnalyticsPost[];
  analysis: FacebookAnalyticsAnalysis | null;
}
