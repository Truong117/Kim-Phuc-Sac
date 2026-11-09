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

When automatic age-based sampling is disabled, the existing `social-metrics:dispatch` also queues manual analytics cycles when the feature is enabled. It is scheduled only when the existing auto-sync setting and scheduler are operational. The embedded consumer is not a scheduler and stops during Render Free sleep; no continuous collection or exact-time delivery is promised. Manual sync works with the existing embedded consumer. No new worker service is provisioned.

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


## Automatic sampling (Phase A, M33)

Apply `2026_11_09_000000_add_facebook_sampling_schedule` with the standard migration tool before opting in. This additive migration stores the persistent schedule/next check on the analytics state and optional intended-time/late markers on samples. Existing samples remain unchanged with null timing classification; no historical counters are fabricated. The reviewed manifest and fixture schema gate now include M33 (33 migrations). No additional package, permission seed, Meta scope, queue or hosted worker service is introduced.

Keep the existing `SOCIAL_METRICS_ENABLED=true`, `FACEBOOK_ANALYTICS_ENABLED=true`, verified Page connection and `social-metrics` queue consumer. New backend settings default off:

| Variable | Initial value | Activation |
| --- | --- | --- |
| `FACEBOOK_ANALYTICS_AUTO_SAMPLE_ENABLED` | `false` | `true` after migration and staging acceptance |
| `FACEBOOK_ANALYTICS_SCHEDULER_MODE` | `off` | `embedded` for the existing supervised container; `external` if an external scheduler invokes the command |
| `FACEBOOK_ANALYTICS_AUTO_SAMPLE_ENTRY_IDS` | empty | Comma-separated **integration entry IDs**, not Meta Page IDs; start with 1–2 test entries |

Empty pilot IDs means all eligible Pages. A malformed nonempty list selects none. Entries must be enabled, Facebook, same organization, connected and backed by a currently authorized active connection principal. Existing access/revision/lease fencing applies before and after provider calls. Removing a pilot entry or disabling the flag stops queued scheduled work before provider I/O/writes. Manual sync and the report picker keep their existing permissions.

The embedded dispatcher runs `facebook-analytics:dispatch` after the existing social boot delay, then every 300 seconds while the backend is awake. It queues at most 20 due Page cycles per pass and makes no provider calls itself. The existing social consumer performs bounded catalogue/sample batches. In `external` mode, run the Laravel scheduler or invoke `php artisan facebook-analytics:dispatch` every five minutes; do not also enable the embedded dispatcher. A consumer must be running in either mode. With automatic sampling on, the legacy hourly report dispatcher does not enqueue full manual analytics cycles that bypass this cadence.

All slots use Asia/Ho_Chi_Minh calendar dates:

| Post age on the sampling day | Intended observation slots |
| --- | --- |
| 0–7 days | 09:00 and 21:00 |
| 8–30 days | 21:00 daily |
| Older, within the 90-day active catalogue | Sunday 21:00 |

Page discovery is checked twice daily (09:00/21:00), independent of the selected dashboard date range. A fresh complete catalogue is reused for sampling-only cycles. The cycle freezes its age/slot anchor so follow-up batches do not create another round of samples when the clock advances. Definition-specific observations after a slot satisfy that slot, including successful manual observations. Initial activation/newly discovered posts start tracking at the actual activation/discovery time rather than being reported late for an interval before tracking existed.

After backend sleep, a single actual observation is collected for each due post, not one artificial observation per missed slot. `sampled_at` and hourly uniqueness always use actual time; `scheduled_for` retains the latest intended slot (bounded by tracking start/publication/discovery). A delay greater than 30 minutes sets `sampling_late=true`. This is a collection-quality marker, not a content-performance score. Render Free sleep means no exact-time or continuous collection guarantee.

Temporary provider/worker failures back off 15, 30, then 60 minutes (rate limiting uses 60 minutes); three failures pause the schedule. Permission/access/finite-cycle failures pause immediately. A token-invalid response keeps the existing reconnect-required behavior. Reconnect/entry authorization changes reset the schedule binding; after diagnosing a transient issue, a successful manual full cycle clears the pause. No automatic retry issues AI calls. Scheduler bookkeeping does not refresh job heartbeat timestamps; stale queued/running cycles fail safely before retry rather than staying alive indefinitely.

The dashboard adds an expandable sampling status: actual latest successful sample, latest due-check, next planned check, due/overdue/never-sampled/latest-late counts across the active 90-day catalogue. Counts are definition-specific and independent of the display date filter. Missing dispatcher confirmation appears only before the first check or more than ten minutes after a missed planned check; normal waiting between slots is not a failure. Blocked/paused/overdue states explain the next operational check. Existing catalogue coverage and metric availability warnings remain separate. Structured `facebook_analytics.sync` events include `mode=scheduled|manual`, safe IDs, counts and error codes, without credentials/provider bodies.

Staging acceptance: migrate with flags off; activate one pilot entry + embedded dispatcher/consumer; verify one successful real cycle and its next check, actual timestamps/late classification, flags-off behavior, and a manual retry. Keep production off until accepted. Tests use fake Meta responses and queues only. Age-normalized D1/D3/D7 evaluation, goals, weekly/monthly scoring and Ads are outside this phase.


## Analytics workspace UI: first implementation phase

The reviewed workspace now separates Page movement, post data/milestone readiness and evaluation/actions. It uses real authorized API data; the standalone design prototype's mock counters and narratives are not shipped. The shared calendar remains the display-period selector, defaulting to the latest seven Vietnam calendar dates.

Page trend is a bar chart of observed net differences assigned to the later sample's Vietnam date. Selecting a day (canvas, keyboard-accessible select or data table) shows all contributing posts, paginated by 25 and sorted by absolute change. Per-post/per-metric day metadata contains first/last valid sample timestamps, pair count and largest gap. Zero and negative differences contribute; one valid sample or an unavailable metric contributes nothing. Gaps greater than 24 hours are disclosed. Existing response-size/read limits and metric-definition boundaries still apply, with no provider calls during reads. Older API payloads without interval metadata show “interval unavailable”, never an invented interval. This view is not exact activity in 24 hours and does not sum unique reach or classify organic/paid activity.

The data-status panel can be expanded; incomplete catalogue, errors and an active cycle are visible without claiming complete Page coverage. Current post lists use cards on phones and tables on larger screens, retaining filters, exports, scoped report links and source detail. Empty series, missing metrics, zero and decreasing counters remain distinct.

The D1/D3/D7 view is a **readiness view**, not completed age-normalized analytics: D1 is publication +24h, D3 +72h, D7 +168h. It can select loaded posts by publication date or D7 target date, disclose posts still waiting for D7 and posts old enough whose milestone is unclassified, and show the future target timestamps in post detail. Milestone values and the D7 median stay null/“—”. Existing 09:00/21:00 samples are not relabelled as exact age milestones, and missing history is not backfilled. Counts are explicitly the loaded read cohort, not a guarantee that all eligible posts have been discovered. Objective labels and robust same-age comparisons are later work.

Evaluation/actions keeps the existing, explicitly requested real AI analysis of the selected observation period. Monthly evaluation is an explanatory readiness state separating observed monthly activity from a publication-month D7 cohort. It does not invent a monthly score, use mock AI text, or create a persistent action plan. Phase order after UI acceptance: verify day-source coverage → schedule/validate age milestones and objective cohorts → weekly/monthly evidence-backed assessments. No new migration beyond M33 and no additional environment variables are introduced by this UI phase.
