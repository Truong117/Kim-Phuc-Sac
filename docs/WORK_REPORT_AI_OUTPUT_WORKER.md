# Work-report analysis: detailed output and supervised worker

## Weekly report: work first, assessment second

New analyses use prompt `work-report-v2.4`, schema `work-report-v2.2` and `source-inventory-v1`. Before calling AI, the server builds a complete work inventory from the authorized, redacted, locked source. Exact matching descriptions are grouped for reading while preserving each original date, result, note, status and item link. Grouping is not a claim that two days describe the same continuous task. The full inventory is available before AI completes and remains available if some AI parts fail. Historical inventories use the frozen snapshot, never today's changed reports.

The employee review receives this inventory first. AI extracts quantitative observations with verbatim evidence and distinguishes incremental results, cumulative figures and unclear figures. The server validates quoted numbers and performs addition itself. For example, sales of 10 and 30 products produce 40 products; 2 images/1 video and 3 images/2 videos produce 5 images and 3 videos. Different metrics and units remain separate. Cumulative, repeated, planned, ambiguously associated quantities or duplicate claims that cannot safely be added show **Cần xác nhận cách cộng**, not an invented total. Numbers still require a human check of their business meaning; structural provenance and correct arithmetic do not certify the model's interpretation. A total from incomplete AI parts is explicitly marked partial.

The company summary receives each successful employee's inventory, calculated subtotals and assessment. Large inputs preserve inventory observations when partitioned, and emit each subtotal only once. This work-first pipeline adds no separate paid synthesis call. The employee tab opens first and presents the work list/quantities before AI comments and proposals. No-source employees receive no invented activity or total. Source counts remain separate from productivity judgments.

Old `v2.3` results remain readable but require a new full revision for the new workflow. They cannot be relabeled or reused as if generated under the new contract. Summary-only regeneration remains available for compatible completed `v2.4` employee parts. No database migration is needed.

This change needs no migration and does not rewrite stored analyses. Keep automatic deployment disabled while an analysis is running. New runs capture the new settings; retrying an old run retains its original limits. To use new limits with successful old employee results, use **Tạo lại riêng tổng hợp**.

## Output and summaries

| Variable | Default | Meaning |
| --- | ---: | --- |
| `WORK_REPORT_AI_EMPLOYEE_OUTPUT_TOKENS` | 6000 | Maximum output per employee part |
| `WORK_REPORT_AI_SUMMARY_OUTPUT_TOKENS` | 16000 | Maximum output per summary batch |
| `WORK_REPORT_AI_SUMMARY_INPUT_BYTES` | 50000 | Summary input ceiling, further reduced for Gateway overhead |
| `WORK_REPORT_AI_SUMMARY_PARTS_PER_BATCH` | 2 | Maximum employee-result fragments per summary batch |

Output settings can be raised to 32000 tokens per call for this feature. Other AI features retain their existing Gateway ceiling. A finite per-call output limit is still required by the API; there is no 3000-token ceiling for new analyses and no 180-character style restriction. Larger output is a budget allowance, not a requirement to pad the report. The prompt and evidence contract remain unchanged.

Large summaries are divided into independently validated batches. An oversized employee result is split at whole claim boundaries, retaining quotes and source IDs. The final overview assembles those AI summaries by section, removes exact duplicates, and retains every validated claim; it does not make an additional unbounded provider call. This is a synthesis of batches, not an extra global model synthesis. Detailed employee results remain available separately. Failed batches remain visible and make the run partial. Retry reuses successful batches.

Summary-only regeneration creates a revision with the same frozen source, provenance links to reused employee units, and zero new employee calls. New feedback applies only to the new summaries. All employee units must be complete and compatible with the current model, driver, prompt and schema. A mock source cannot be reused as real AI output. Existing per-run and monthly budgets still apply; no attempt counters or budget ledgers are reset by retry.

## Render rollout

1. Let the active analysis finish, then merge the reviewed change. Keep automatic deployment disabled until the following configuration is ready.
2. Before deploying, set the Render service's `maxShutdownDelaySeconds` to **300**. The default 30-second grace period is too short for an in-flight AI call. This is a service setting, not an environment variable.
3. Clear the temporary **Docker Command** override so the image's versioned `CMD` runs. Alternatively set it to `sh /app/scripts/start-service.sh`. Keep `WORK_REPORT_AI_QUEUE_CONNECTION=work_report_ai`; `WORK_REPORT_AI_WORKER_MODE=embedded` is the default.
4. Deploy the reviewed commit once with this configuration. No additional shell command, database change, or package is required.
5. Verify `/up` and `/api/health`, then create one scoped analysis. Progress should advance through employee and summary batches. A large run has more than the former seven units.

The supervisor runs the web server, an auto-restarting queue worker, and recovery every 60 seconds. The initial worker/recovery delay is 60 seconds to let an old unsupervised container drain during the first rollout. It forwards SIGTERM and waits for the worker's current job. Worker timeout is 270 seconds, the new unit lease is 420 seconds, and queue retry-after is 480 seconds. Recovery marks expired running units `AI_OUTCOME_UNKNOWN`, exposes explicit retry, and never automatically repeats an ambiguous provider call. Diagnostic logs include only analysis ID, unit ID and error code.

Embedded workers share the web instance's CPU and memory. A suspended Free instance does not process background work while asleep. For continuous production processing use an always-on instance or a separate background worker; do not assume this change removes hosting resource limits. For a separate worker, set the web mode to `external`, run `sh /app/scripts/start-service.sh worker` on the worker service and run `sh /app/scripts/start-service.sh recovery` as a supervised companion. Both need the same database, application key, feature configuration and credentials. Never run multiple recovery loops intentionally.

## Validation

Feature tests cover batched completion, interrupted batch recovery, selective retry, paid-call counts, immutable source history, summary-only mode/idempotency, incompatible sources, and unchanged budgets. Unit tests cover lossless input partitioning, unrepresentable claims, output allowances and timeout ordering. `python3 backend/tests/Deployment/test-ai-supervisor.py` exercises the actual entrypoint with fake children, including worker crashes and SIGTERM during a job, without a database or provider call. The existing container health/concurrency smoke test still runs in CI.
