# Facebook analytics V1

## Scope

`/social-analytics/facebook` adds a real API-backed analytics workspace using the approved KPS prototype layout. Overview, content and AI tabs use no production mock fallback. Meta Ads, new Page Insights metrics, follower/reach data, image/caption AI analysis and Google document ingestion are outside V1.

Reuses verified `social_connections` tokens and the `social_metrics` connection/`social-metrics` queue. Does not change report picker ownership or existing report-link snapshots. New Page catalogue/samples live in separate tables. Therefore a post can be analysed without ever appearing in a daily report. A report evidence association is not proof of exclusive authorship or employee performance.

## Release and activation

1. Review PR and merge to staging first. Run the standard migration tool for `2026_11_07_000000_create_facebook_analytics_tables` before deploying/activating the feature. Four tables are additive; existing report/token data is unchanged. No new permission seed or package is required.
2. Keep `SOCIAL_METRICS_ENABLED=true`, the verified `META_GRAPH_VERSION`, existing Page connection and queue consumer. Set `FACEBOOK_ANALYTICS_ENABLED=true` to expose the page/API/navigation after migration.
3. For AI only, opt in with `FACEBOOK_ANALYTICS_AI_ENABLED=true`. Uses the existing daily AI gateway credentials/model/prices and the shared OpenAI monthly budget pool. The cap is the minimum positive configured daily/KPS/weekly cap; this is not another monthly allowance. Missing key/prices/budget refuses requests. There is no fake AI fallback.
4. Click sync once and allow embedded consumer startup delay. One cycle automatically queues bounded follow-up batches, yielding five seconds between batches until discovery and sampling finish. The UI polls active cycles and reports progress separately from the selected display period. Acceptance into a queue is not proof of completion. An hourly bucket permits one observation per post/metric definition/hour; growth requires a later hour's observation. Errors stop the cycle with the saved samples preserved; an explicit retry starts a new fenced cycle.
5. Verify assigned staff, unrelated staff, organization managers, expired token, empty catalogue, missing views, observed growth, AI configuration/budget and exported CSV on staging before a separate production release. No real Meta/AI call or database migration is performed by development/CI.

The existing `social-metrics:dispatch` also queues analytics when the feature is enabled. It is scheduled only when the existing auto-sync setting and scheduler are operational. The embedded consumer is not a scheduler and stops during Render Free sleep; no continuous collection or exact-time delivery is promised. Manual sync works with the existing embedded consumer. No new worker service is provisioned.

## Access

Active KPS membership is required. Organization-wide viewers are users with `integrations.manage`, or `integrations.view` plus organization/all `reports.view` scope. Other users need `reports.view` and exact `responsible_user_id` assignment. Department/own report scope alone never grants all Pages. Organization boundaries always apply, including for global roles. The server gates page metadata, data, topic edits, sync, AI and navigation; hiding a button is not the access boundary.

Report links on a post are limited separately through `ReportScopeService` and current evidence links/manual selections, and only reports in the selected period are listed. A manager can see Page performance without receiving inaccessible report IDs. No report body or staff identity enters AI input. Disabled/deleted/non-Facebook entries are excluded. Disconnected Page history can be read by currently authorized users, while new sync/AI is blocked.

## Catalogue and sampling

Each sync fetches at most two 25-post provider pages, with a frozen 90-day discovery window and an encrypted opaque continuation cursor. It follows only the fixed Graph endpoint and provider-returned Page-owned numeric IDs; never a supplied next URL. Continuation resumes in later jobs. After catalogue completion, remaining batches only sample pending posts. Once the whole cycle finishes, a later explicit request or configured scheduled dispatch begins a new cycle to discover new/edited posts. Catalogue completeness always means the last discovery cycle, not a real-time guarantee. A hard active catalogue bound fails explicitly. Invalid/stalled cursors reset discovery for the next attempt.

Then at most ten least-recently-observed posts in the active window are sampled, prioritizing never-sampled/newer posts. A cycle freezes its 90-day window, metric definition and target sampling hour. Continuations do not restart catalogue discovery after reaching its end, or re-sample earlier batches merely because the clock crossed an hour. Each job carries a cycle ID and sequential batch number; superseded/duplicate jobs and failure callbacks cannot overwrite a newer cycle. The cycle stops on provider errors, revoked access, disconnect/revision change, or the finite 600-batch ceiling. Legacy queued payloads can start only when no modern cycle is active. Progress and safe error codes live in the existing state JSON; no extra migration is needed. Structured facebook_analytics.sync logs record batch_started, batch_completed, completed or failed with counts and no token/caption/provider response. Per-hour uniqueness prevents duplicate snapshots. Jobs have 270s timeout/360s lease on the existing 480s visibility queue. Queue payloads contain IDs and a batch sequence only, never provider credentials. Entry version, connection revision, organization, requested user's current access, connection principal and lease are rechecked after provider I/O and before every write. Revoked connections cannot resume stale writes. Raw provider errors are not returned/logged; error code 190 marks the connection reconnect-required.

Stored metadata: public caption (max 5,000 characters), fixed normalized Facebook URL, validated HTTPS Facebook CDN preview URL, publication timestamp, media type, optional allowlisted topic. Captions render as escaped text, never HTML. Photos may expire on the CDN and refresh on discovery. No private comments/messages are fetched.

## Numeric semantics

- `likes` means LIKE reactions only; comments have their own count. Optional lifetime display/play metric retains its real definition, not a promise of video views or reach.
- Zero stays zero; unavailable stays null; negative observed changes remain negative.
- The latest definition observed in the selected period selects the metric series for that response. Different definitions never share a baseline, total or comparison; older historical periods can still use their original definition.
- Current counters are the last observation within the selected period; they are not period activity totals. Posts without observations in that period have missing counters.
- Growth is last minus first valid metric observation within the period, requiring at least two valid observations. It covers observed intervals only, not necessarily the full calendar period.
- Previous-period comparison uses the same posts with valid observations in both periods and an identical definition. The UI explicitly gives this cohort's size and both values, separately from all-post growth. Publication counts only compare when the completed catalogue covers both periods.
- Trend allocates an interval's net change to the local Vietnam date of the later sample. It is explicitly not exact activity in 24 hours. Null gaps are not plotted as zero or connected through. Screen-reader data table accompanies the canvas chart.
- Catalogue/post counts and missing metrics are visible. No sums of daily lifetime counters, unique-reach sums, organic/paid attribution or employee score are computed.

Read limits: at most 90 selected days, 1,000 relevant posts, 20,000 samples including previous-period context and 5,000 report evidence items. Responses over 4 MiB fail explicitly. Limits fail explicitly rather than truncate. The content table filters the loaded publication cohort and paginates by 25; CSV includes the full filtered cohort and guards spreadsheet formula injection.

## AI

Explicitly requested only. Queued analyses freeze an encrypted, versioned source and SHA-256. Input contains aggregate numeric context and up to 30 posts with at least two observations, ranked by observed LIKE+comment change; selected/eligible counts disclose this subset. Uses internal post IDs, type, allowlisted topic and metric definitions/intervals. No caption, URL, Page name, token, staff identity, report IDs or report text is sent. This first version reasons about metrics and manually assigned topics, not the visual/copy quality of a post.

Output is schema-validated with bounded text and references restricted to selected source IDs. Users can open each referenced post and verify its numeric source; the numeric table remains authoritative. New data marks old results stale. Source-identical requests reuse the queued/completed analysis; external calls remain budgeted/idempotent through `OpenAiGateway`. Invalid output is rejected, never presented as a valid AI result. Access is rechecked before the provider call and completion. Interrupted jobs are surfaced and only explicitly requested retries can allocate a replacement after the execution window; no background loop repeats ambiguous provider calls.

## Verification

Automated Meta/AI transport fixtures only. Backend tests cover ownership/org boundaries, revocation during I/O, continuation, separate report data, hourly deduplication, nullable/zero/negative counters, definition changes, comparison cohorts, timezone, topic scope, encrypted AI requests and idempotency. Browser tests cover real API contracts, shared date picker, filtering/detail/report links, mobile overflow, AI evidence and stale-response prevention. Existing report/social tests remain required. Live Page availability and metric meaning still require operator acceptance; no new Meta scope is claimed as universally available.

Migration is appended as M32 in the reviewed manifest; legacy prefixes remain unchanged. The staging AI acceptance loader additionally checks all four analytics tables against M32 and refuses ledger/schema mismatch. The older M16–M22-only migration launcher is not expanded; use the current standard migration tool for M32.
