# Work-report AI budget recovery

## Resulting behavior

A monthly-budget rejection before the provider call no longer consumes a unit attempt or a run reservation. A summary with no completed employee parts also consumes neither. Old runs use the current `WORK_REPORT_AI_MONTHLY_BUDGET_USD` for admission, including reductions and disablement, while retaining their original sources, model, token prices, output caps and prompt/schema contract.

Changing only the monthly cap reuses the existing canonical analysis and validated employee results. It does not create new versions for unchanged source data. Explicit revisions remain distinct. Other configuration changes retain their existing cache behavior.

The Gateway identifies an explicit HTTP 429 `insufficient_quota` response without usage as `AI_PROVIDER_QUOTA_EXCEEDED`, stops automatic HTTP retries, and settles that rejected attempt at zero. If an earlier attempt in the same request had an ambiguous failure, its conservative charge and the worker attempt/run reservation remain. Timeouts, server errors, stale leases and validation failures are not refunded by this change.

No migration or new dependency is required. The normal authorization, frozen-source checks and worker lease fence remain in effect.

## Recovery of existing exhausted units

New behavior cannot safely infer the history of an old unit from its latest error or a null `ai_usage_record_id`: failed HTTP calls can also lack that reference. An operator must first verify the blocked attempts against logs and SQL, then supply a reviewed JSON receipt.

The receipt contains the analysis ID/end date/expected UTC update timestamp, expected accumulated run reservation, summary attempt count, and each employee unit ID with its expected attempt count and the number of proven monthly-budget rejections. It contains no report content, identities or credentials.

```sh
php artisan work-report-ai:resume-budget ANALYSIS_ID --evidence=/tmp/recovery.json
```

This default mode is read-only. It reports the exact proposed metadata refund and whether the current monthly ledger can admit the first request.

After reviewing the plan and ensuring headroom is available:

```sh
php artisan work-report-ai:resume-budget ANALYSIS_ID --evidence=/tmp/recovery.json --apply --queue
```

The command locks and checks the run and its units, refuses stale/mixed/active/used units, subtracts only the receipt's proven blocked attempts and corresponding run reservations, and resets the unexecuted no-parts summary's attempts. Repeating the metadata repair before queuing is a no-op. It preserves snapshots, historical cache identity, monthly spend, monthly reservations and all actual/ambiguous usage. A fixed audit event records the receipt hash and refund amount.

`--queue` explicitly requests the normal retry flow under the original requester's current access. It is refused before repair if the first call does not fit the monthly cap. A queued request is not a completed result: verify the terminal run state and error codes through the application/logs. Do not run a global retry of historical analyses just to test a budget setting.

For the reviewed 2026-11-23 analysis `2430011`, the supplied SQL/log evidence identifies eight employee units with two monthly-budget rejections each, and a derived summary with no valid parts. At the saved reservation of USD 0.58 per call, the proposed refund is `8 * 2 * 0.58 = 9.28` USD of **run reservation**, not a refund of a provider charge. Monthly accounting is unchanged.

## Budget proposal and rollout

The owner reports about USD 15 of first-month provider spend, including repeated trial analyses. Recommend **USD 20/month for normal operation**, retain **USD 10/run**, and keep the current model/prices/output caps. Re-evaluate after a month without repeated trial runs; USD 40/month is not supported by the reported usage.

The November internal ledger is USD 19.776751 with no running reservation, leaving USD 0.223249 under the USD 20 cap. The next saved work-report call reserves USD 0.58, so metadata repair alone cannot admit it. Provider top-ups do not alter this internal ledger. The difference from the approximately USD 15 Billing figure can include configured-price estimates, cache discounts and conservative unknown-usage accounting; do not overwrite the ledger with Billing or erase genuine spend without a reconciled receipt.

If November catch-up must run immediately before that reconciliation, propose a **temporary November cap of USD 25**, then USD 20 for the next monthly period. This is an optional cap change requiring the owner's decision, not a new fee or an automatic purchase. It has not been applied by this patch.

Daily-document AI currently has a USD 5 cap on the same environment/provider ledger, not an independent USD 5 pool. Audit `KPS_AI_MONTHLY_BUDGET_USD`, `WORK_REPORT_AI_MONTHLY_BUDGET_USD` and `DAILY_REPORT_AI_MONTHLY_BUDGET_USD` together before a separate daily-document rollout. This hotfix repairs work-report analyses; it does not silently requeue old daily documents or modify their stored configuration.

Release the hotfix through the repository's `hotfix/*` review flow, verify backend/frontend and workers use the reviewed commit, then run the targeted recovery. The local SQL audit for 23–28 November lists other affected analyses for individual evidence review after the first run is verified.
