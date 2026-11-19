# Facebook synchronization: identifying and isolating unavailable posts

Page analytics and report-linked social metrics share one tracking decision per Page connection and Facebook post ID.

## Operator workflow

1. Apply migration `2026_11_19_010000_create_facebook_post_tracking` (M46 on this branch) before deploying the backend and frontend in each environment. Existing posts and samples need no backfill.
2. In **Phân tích MXH**, select the affected Page and click **Đồng bộ Page**. This is distinct from the report-linked sync action in Integrations. An explicit analytics sync can run after a paused automatic cycle; a successfully completed cycle resumes automatic sampling.
3. Expand data status to see unreadable or stopped posts for the selected Page. The employee assigned to the Page can confirm that a duplicate was hidden and stop tracking it, or resume tracking it later. The control affects KPS sampling only.
4. Search production application logs by `facebook_metrics.post_failed`, `facebook_metrics.post_skipped`, `facebook_metrics.page_probe_failed`, or `facebook_analytics.provider_failed`. For report-linked metrics, also search `social_metrics.provider_failed`.
5. Match `entry_id`, `external_id`, `post_url`, and (for analytics) `run_id` / `batch`. Provider diagnostics contain `operation` (`reactions`, `comments`, `insights`, or `published_posts`), `http_status`, `provider_code`, `provider_subcode`, a bounded/redacted `provider_message`, and `provider_trace_id`. No raw response, Authorization header, token, proof, cursor, or exception trace is logged.

## When a post is skipped

For provider errors the client only isolates the explicit object-unavailable response (HTTP 400/404, Meta code 100 / subcode 33) on required reactions/comments calls. Because that response is ambiguous, it also probes Page discovery and the **same metric edge on another current post**. For a confirmed empty catalogue the worker can skip the post for this cycle only. It checks up to two eligible peers from the first five candidates. A nonempty response with no verifiable peer, a malformed catalogue or a Page-wide permission failure stops the run.

Token/authentication, explicit permission, rate-limit, transient/server, and unknown failures stop the run using the existing reconnect/backoff behavior. Missing optional views leave the real LIKE/comment values usable. Responses without any numeric metric skip that observation and cannot clear an existing failure streak.

The first verified isolated failure is retried on the next eligible run. Two distinct failed runs defer that post for one day. Further verified failures after retry extend its delay to two, four and then seven days. A successful sample clears the streak. A retry timestamp is the earliest permitted attempt. Actual observation depends on the worker, sampling window and job schedule.

A confirmed stop excludes the post from manual Page sync, automatic cadence and milestones, report-linked metric retrieval, and overdue alerts. Prior samples remain available for analysis. KPS does not create zero observations during a gap. When an assignee resumes tracking, the post becomes eligible under the usual sampling rules.

If the run stops on an unclassified response, use its provider diagnostics to determine the actual cause before expanding the skip rules. Do not replace a Page token solely because a post is unavailable.
