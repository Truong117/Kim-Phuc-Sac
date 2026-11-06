# Facebook evidence picker for daily reports

Scope: daily and makeup reports, quick and detailed editors. No ads-account connection, advertising analytics, TikTok, Zalo or Drive changes.

- Employees can add several Page posts as separate evidence links, up to the existing ten-link limit per work item. For an existing Facebook link, choose exactly one corresponding post; retain the original link.
- The modal uses the connected Page's authenticated `published_posts` list, 25 records per request. It follows only a bounded opaque cursor through a fixed Graph endpoint, never the returned `paging.next` URL. The picker has no 300-post catalogue cutoff. Date range defaults to the seven days ending on the report date (including makeup reports), with a maximum one-year query range. Meta's returned records are displayed newest first. Search/type filters apply to loaded records; the UI says so and offers load-more.
- Preview uses escaped text, date and an optional Meta CDN image, with a link to Facebook for the full content. Hover, focus and tap open preview independently of selection. No HTML embedding, autoplay, login cookie forwarding or public alias scraping.
- Confirmation updates the unsaved report draft, not the database. Cancel leaves evidence unchanged. The report's existing save/lock/ownership rules govern persistence. Changing an original link clears its choice. Selection cancels pending automatic checks for that link. A saved choice records actor/time and remains distinct from automatic matching.

## Server authority and persistence

New nullable `daily_report_items.facebook_selections` JSON stores the source URL, entry/Page identity, numeric Graph post identity, canonical URL, Page name, revision and server-written selection audit. Provider caption/thumbnail text is not persisted as report content or sent into AI context.

The listing and opaque cursors are scoped to the active KPS organization, actor, Page connection revision, date range and original source URL. Candidate application proofs are encrypted/authenticated with APP_KEY. These are not Meta tokens. New selections require a valid actor-bound proof issued within seven days and a still-enabled Facebook Page connection with an authorized connection principal. A changed connection during provider I/O produces no candidates. Neither client-supplied IDs nor arbitrary external URLs are accepted as ownership evidence.

An unchanged persisted choice survives unrelated edits, token rotation, or an unavailable connection. Report responses issue an application proof only to an editable report's owner. Saved choices cannot override report locks or authorize another employee's report. Omission from an older client preserves still-present choices; an explicit empty selection array removes them.

Metrics sync uses the saved Graph post ID for a manual choice, without resolving the share URL or scanning the newest-300 catalogue. Mapping remains specific to the report item and exact original source. Multiple reports may reference the same post without duplicating its metric snapshot. Source hashes and choices are rechecked before worker writes; changing links/choices immediately removes existing associations. Null/unavailable metrics retain the existing behavior.

## Deployment and verification

Apply migration `2026_11_06_000000_add_facebook_selections_to_report_items` (M30) to the intended staging database before deploying the new report save path. It adds one nullable JSON column; existing links and metrics are unchanged. No new package, worker, cron or environment variable is needed. Existing social metrics configuration and APP_KEY remain required. Do not rotate APP_KEY to refresh a Facebook token.

CI covers Page ownership, cursor/ticket binding, tampering, revoked connection, durable report save/edit, link invalidation, direct-ID sync, and browser interactions for daily/makeup/mobile. These use provider mocks; after staging deployment, verify with real KPS Page data: posts, image previews, Reel, older-page pagination, confirm/save/reload, changed original link, and metrics sync. The picker does not guarantee Meta exposes every Page item or every metric.
