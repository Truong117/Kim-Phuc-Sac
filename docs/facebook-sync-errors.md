# Facebook synchronization: identifying and isolating unavailable posts

Applies to Page analytics and report-linked social metrics. No migration or new environment variable is required.

## Operator workflow

1. Deploy the reviewed backend and frontend together, following the normal staging → main release process.
2. In **Phân tích MXH**, select the affected Page and click **Đồng bộ Page**. This is distinct from the report-linked sync action in Integrations. An explicit analytics sync can run after a paused automatic cycle; a successfully completed cycle resumes automatic sampling.
3. Expand data status. A completed run can have sampled posts and separately listed skipped posts. The warning shows links for up to 50 skipped posts. It never counts a skipped post as sampled.
4. Search production application logs by `facebook_metrics.post_failed`, `facebook_metrics.post_skipped`, `facebook_metrics.page_probe_failed`, or `facebook_analytics.provider_failed`. For report-linked metrics, also search `social_metrics.provider_failed`.
5. Match `entry_id`, `external_id`, `post_url`, and (for analytics) `run_id` / `batch`. Provider diagnostics contain `operation` (`reactions`, `comments`, `insights`, or `published_posts`), `http_status`, `provider_code`, `provider_subcode`, a bounded/redacted `provider_message`, and `provider_trace_id`. No raw response, Authorization header, token, proof, cursor, or exception trace is logged.

## When a post is skipped

The client only isolates the explicit object-unavailable response (HTTP 400/404, Meta code 100 / subcode 33) on required reactions/comments calls. Because that response is ambiguous, it also probes Page discovery and the **same metric edge on another current post**. If discovery returns an empty list, the Page remains readable but has no current posts. A malformed probe or a probe that cannot confirm metric access stops the run instead of hiding a Page-wide failure.

Token/authentication, explicit permission, rate-limit, transient/server, and unknown failures stop the run using the existing reconnect/backoff behavior. Missing optional views are logged separately; LIKE/comment values remain usable, and views remain null.

Skipped analytics IDs are retained only in the existing run's JSON counts and excluded from that run's remaining ordinary and urgent milestone batches. They are retried in the next cycle. Each skipped post is logged; only the first 50 links are returned to the UI. The API resolves those links under the currently authorized Page and organization, without exposing internal run or provider diagnostics.

No sample, zero, post `sampled_at`, or milestone is fabricated for a skipped post. Existing samples remain intact. Data-health indicators can therefore still show missing/due observations after a run completes with skips.

If the run stops on an unclassified response, use its provider diagnostics to determine the actual cause before expanding the skip rules. Do not replace a Page token solely because a post is unavailable.
