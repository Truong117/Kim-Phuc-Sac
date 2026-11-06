# Facebook report-link tracking V1

This extends the integration directory in the same draft PR. It is read-only toward Facebook. TikTok, Zalo and Meta Ads are not implemented here. No live Page, live token, database or production service has been accessed during development.

## Activation and operations

Apply M28 (directory) and M29 (`2026_11_05_010000_create_social_tracking_tables`) through the normal reviewed migration procedure before deploying this backend/frontend. Do not run the old bootstrap tool against an existing production database.

Feature switches default OFF:

- `SOCIAL_METRICS_ENABLED=true` enables the Facebook adapter/reading metrics.
- `META_GRAPH_VERSION` is required (e.g. the Graph version approved for your Meta application); no implicit API-version selection.
- `META_APP_SECRET` is optional server-only app-secret proof material when required by the Meta application.
- `META_POST_VIEWS_METRIC` is optional and defaults unset. Select a supported **post lifetime** metric only after checking its meaning and response on the live Page/API version. An example candidate for operator verification is `post_media_view`; this is media display/play activity, not a guarantee of a specific video-view definition. Invalid/unavailable metric data stays null. No impressions/unique reach values are silently relabeled as video views.
- `SOCIAL_METRICS_AUTO_SYNC=true` opts into the hourly scheduler command.

In Integration -> MXH -> Facebook entry -> Detail, an authorized manager enters the Page ID and a Page access token already authorized to read the Page and relevant insights. GET `/me` must match that Page ID. The token is encrypted using Laravel's existing APP_KEY, hidden from serialized models, never returned, and cleared from the form after submission. This first version uses manual Page-token onboarding; it is not an OAuth/App Review workflow. Real token validity, Page permissions, API scopes and metric availability still require live acceptance. Keep APP_KEY stable according to the existing production key-management procedure.

Start a dedicated worker with the dedicated database-backed queue connection:

`php artisan queue:work social_metrics --queue=social-metrics --timeout=300 --tries=1`

### Staging without Shell

The web container can opt into a supervised consumer with `SOCIAL_METRICS_WORKER_MODE=embedded`. The default is `off`; `external` leaves consumption to a separately managed worker. Also set `SOCIAL_METRICS_ENABLED=true` and the same `META_GRAPH_VERSION` verified in Explorer, then deploy the updated backend. Keep `SOCIAL_METRICS_AUTO_SYNC=false` during manual acceptance. This requires no new migration, Shell session or additional service.

The consumer starts after a 60-second boot delay and handles the `social-metrics` queue independently from both AI queues. It restarts after a process exit, recycles after one hour or the 96 MB between-job memory threshold, and receives SIGTERM through the existing container supervisor. The dedicated `social_metrics` connection uses a 480-second visibility timeout, longer than the job's 300-second timeout and 360-second lease. Existing unreserved jobs in the same configured jobs table/queue remain consumable after the connection change.

For acceptance: save a recent canonical Page-post link in a staging daily report, click Sync once, allow boot delay/processing, then reload the detail. Check successful sync time, coverage and counters rather than treating a queue-accepted response as completion. Startup logs include `Social metrics embedded worker started`; secrets are not logged. A host shutdown deadline can interrupt an in-flight job: inspect last sync/error/coverage and submit a fresh manual sync after the service returns when needed. No migration or provider call is added to startup itself.

This embedded consumer does not run the scheduler. Automatic collection still requires the scheduler described below. It shares the web instance's CPU/RAM and stops whenever that instance is stopped or sleeping; a Free web instance does not provide continuous background collection. No keep-awake traffic is added. For continuous operation use an appropriate always-on worker deployment and `SOCIAL_METRICS_WORKER_MODE=external`.

The scheduler must run `php artisan schedule:run` each minute through the deployment's scheduler mechanism. `social-metrics:dispatch` queues jobs; the manual sync button also queues rather than calling Facebook in the report-save request. No credentials are present in jobs, only an integration ID. A connection lease/revision fences disconnected, edited or replaced connections; jobs recheck the manager's active permissions and source report scope.

## Discovery, ownership and limitations

Each connection scans only the manager's visible report items in the last 30 days, with limits of 5,000 evidence-bearing items and 2,000 Facebook links. Exceeding a source bound produces an explicit error, not silent truncation. Current evidence_links are used; no arbitrary URL in the report is fetched.

GET `/{page-id}/published_posts` supplies at most three 100-item pages. Only provider-returned IDs for that Page are eligible. Evidence links are compared with those canonical permalinks, numeric post IDs and returned attachment target IDs. Supported common forms include Page/posts links and numeric video/watch/reel links when the Page's published-post attachment identifies them. Opaque short share links, aliases that cannot be matched, posts outside that provider window and some Reels are explicitly not guaranteed. They remain unmatched in the coverage count; use a canonical original link. This is not a public-web scraper and it does not fetch arbitrary third-party posts.

The same remote post is stored once per integration. Sources link back to multiple report items without duplicate metric totals. Only ten least-recently-sampled matched posts are collected in a job; remaining posts are counted as deferred and rotate into following runs. Numeric likes use the reactions edge filtered to type LIKE; comments use their own summary edge. LIKE counts are distinct from all reactions. The optional lifetime views metric is requested separately.

## Stored data and period semantics

- social_connections: encrypted token, verified Page identity, connected-by principal, revision/lease, last successful sync, safe error code and coverage.
- social_posts: owned remote ID and canonical permalink.
- social_post_sources: current-report-item associations and canonical submitted-link hash.
- social_metric_snapshots: observed timestamp, UTC hourly bucket, definition (API version plus metric mapping) and nullable counters.

Snapshots are append-only and unique per post/definition/hour. Definition changes never mix baselines. Missing/unsupported values remain null; true zero remains zero; negative net changes remain negative to reflect corrections/unlikes. No daily cumulative values are added together. A single observation has no growth delta. Every result includes observed_from/to, sample_count and coverage `insufficient` or `observed_interval_only`. This is the measured interval within the selected period, not an exact full-calendar-period activity count or reconstruction of the past.

The detail selector chooses report dates for 1/7/30 days and observations within that period. It describes work evidenced by those reports, not a complete all-post Page analytics dashboard. Historical source reports can be selected in longer periods. Unchanged counters, late sync, unavailable metrics and provider corrections must not be presented as proof of employee productivity, exclusive ownership or organic-versus-paid causation.

Directory URL/platform changes disconnect and clear credentials. Deleting a directory entry disconnects it and detaches its historical posts rather than deleting snapshots; detached posts are excluded from new scoped results. Removing evidence from a report item removes its visibility on read, even before the next sync. Existing immutable AI runs retain their frozen input.

## AI integration

At new work-report snapshot capture, authorized report items select safe numeric metric context. The frozen source hash includes this context. Employee-unit input is filtered to that unit's item/report IDs in both raw-report and daily-document modes, with existing byte limits enforced. Tokens, external text, URLs and user-entered channel names are excluded. AI receives a fixed policy about observed intervals, nulls, corrections, deduplication and avoiding exclusive attribution/paid-organic inference. No new AI request is made by synchronization. Company summaries continue to derive from successful employee analyses; the canonical metric table remains the authoritative numeric display.

## Source contracts and verification

Meta-generated primary SDK contracts consulted:
- https://github.com/meta/facebook-python-business-sdk/blob/main/facebook_business/adobjects/page.py (`published_posts`)
- https://github.com/meta/facebook-python-business-sdk/blob/main/facebook_business/adobjects/pagepost.py (post permalink, shares, likes/comments/attachment edges)
- https://github.com/meta/facebook-python-business-sdk/blob/main/facebook_business/adobjects/post.py (`insights` request shape)
- https://github.com/meta/facebook-python-business-sdk/blob/main/facebook_business/adobjects/insightsresult.py (`values`/`total_value` response fields)

Meta's hosted reference could not be fetched in this environment. No live acceptance or universal metric availability is claimed; the version/optional views metric must be verified for the actual Page before enabling scheduled collection. Fixtures are not evidence of live API permission or historical-data availability.
