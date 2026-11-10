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
  intervals?: Partial<Record<FacebookMetric, FacebookObservationInterval>>;
}
export interface FacebookObservationInterval {
  from: string;
  to: string;
  pairs: number;
  max_gap_seconds: number;
}
export interface FacebookAnalyticsPost {
  id: number;
  external_id: string;
  url: string;
  message: string;
  picture: string | null;
  type: "reel" | "video" | "photo" | "post";
  topic: FacebookTopic | null;
  objective?: FacebookObjective | null;
  distribution?: FacebookDistribution | null;
  milestones?: Record<FacebookMilestoneKey, FacebookMilestone>;
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
  milestones_enabled?: boolean;
  reviews_enabled?: boolean;
  sampling?: FacebookSamplingHealth | null;
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

export interface FacebookSamplingHealth {
  enabled: boolean;
  status:
    | "off"
    | "blocked"
    | "paused"
    | "queued"
    | "running"
    | "overdue"
    | "unconfirmed"
    | "waiting";
  total_posts: number;
  due_posts: number;
  overdue_posts: number;
  never_sampled_posts: number;
  late_posts: number;
  last_checked_at: string | null;
  next_check_at: string | null;
  last_sampled_at: string | null;
}

export type FacebookObjective =
  "awareness" | "engagement" | "traffic" | "leads";
export type FacebookDistribution = "organic" | "paid";
export type FacebookMilestoneKey = "D1" | "D3" | "D7";
export interface FacebookMilestone {
  day: number;
  due_at: string;
  status: "waiting" | "due" | "missing" | "on_time" | "late";
  sampled_at: string | null;
  delay_seconds: number | null;
  definition: string | null;
  source: "periodic" | "milestone" | null;
  metrics: FacebookMetricValues;
}
export interface FacebookMilestonePost {
  id: number;
  message: string;
  url: string;
  type: FacebookAnalyticsPost["type"];
  topic: FacebookTopic | null;
  objective: FacebookObjective | null;
  distribution: FacebookDistribution | null;
  published_at: string;
  milestones: Record<FacebookMilestoneKey, FacebookMilestone>;
}
export interface FacebookMilestoneGroup {
  identity: Pick<
    FacebookMilestonePost,
    "type" | "topic" | "objective" | "distribution"
  > & { definition: string };
  current: FacebookCohortStatistics;
  previous: FacebookCohortStatistics;
  comparison: Record<
    FacebookMetric,
    { reason: string | null; difference: number | null; percent: number | null }
  >;
}
export interface FacebookCohortStatistics {
  posts: number;
  waiting: number;
  metrics: Record<
    FacebookMetric,
    { median: number | null; posts: number; coverage: number }
  >;
}
export interface FacebookMilestoneData {
  basis: "matured" | "published";
  from_date: string;
  to_date: string;
  previous_from: string;
  previous_to: string;
  as_of: string;
  catalogue_covered: boolean;
  policy: {
    version: number;
    window_minutes: number;
    minimum_posts: number;
    minimum_coverage: number;
  };
  posts: FacebookMilestonePost[];
  groups: FacebookMilestoneGroup[];
}

export interface FacebookReviewAction {
  id: string;
  title: string;
  expected_result: string;
  metric: FacebookMetric | null;
  due_date: string;
  review_date: string;
  status: "planned" | "doing" | "done";
  result_note: string;
}
export interface FacebookReviewPlan {
  version: number;
  expected_posts: number | null;
  actions: FacebookReviewAction[];
  updated_at: string | null;
  source_changed: boolean;
}
export interface FacebookPeriodReviewData {
  entry_id: number;
  entry_version: number;
  from_date: string;
  to_date: string;
  metric: FacebookMetric;
  previous_from: string;
  previous_to: string;
  as_of: string;
  source_hash: string;
  catalogue_covered: boolean;
  publication: { count: number; covered: boolean };
  quality: {
    total: number;
    statuses: Record<FacebookMilestone["status"], number>;
  };
  policy: FacebookMilestoneData["policy"];
  owner: { id: number; name: string } | null;
  plan: FacebookReviewPlan;
  groups: {
    id: string;
    identity: FacebookMilestoneGroup["identity"];
    current: FacebookCohortStatistics["metrics"][FacebookMetric];
    previous: FacebookCohortStatistics["metrics"][FacebookMetric];
    current_total: number;
    previous_total: number;
    comparison: FacebookMilestoneGroup["comparison"][FacebookMetric];
    status: "improved" | "declined" | "unchanged" | "insufficient";
    evidence: {
      kind: "typical" | "highest" | "lowest";
      id: number;
      message: string;
      published_at: string;
      point: FacebookMilestone;
    }[];
  }[];
}
