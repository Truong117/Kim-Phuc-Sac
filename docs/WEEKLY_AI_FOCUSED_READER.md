# Weekly AI: focused reading and grounded decisions

The period report keeps four tabs: **Toàn công ty**, **Theo nhân viên**, **Lịch sử phân tích**, and **Báo cáo nguồn**. The company tab opens first. A saved run collapses its period/department fields; actions and warnings remain available. Periods 1/7/30 days remain supported; the default is seven days.

## Output contract

`work-report-v3.0` retains the seven persisted section arrays and thematic `work_groups`. This preserves historical reads and the bounded employee/summary pipeline. Recommendations now require `priority`, `rationale`, `impact`, `action`, `expected_outcome`, `verification`, and `decision_needed`, in addition to `text` and verbatim `evidence`. The last field may be empty. All other reasoning fields must be nonempty and bounded. `priority` is a management suggestion, never an employee score. No owner or deadline is invented.

Both the provider schema and worker validation enforce the current contract. Historic two-field proposals can still be read/validated, but cannot be accepted from a new provider response. Old contract jobs cannot resume under new instructions. Users must explicitly request a new run; saved results and usage history are untouched.

The seven storage arrays are presented as grouped work, clarifications, observations, and support proposals for employees. Company reading starts with conclusions and bottlenecks, followed by proposals. Sources and raw daily observations are expandable at the end. Only confirmed, finite, nonnegative totals appear in the work summary; other metrics are listed once under clarification without repeated confirmation labels.

## Daily input and company synthesis

When `WORK_REPORT_AI_USE_DAILY_DOCUMENTS=true`, the existing coverage gate still requires completed, current daily documents. Only frozen work groups and quantitative observations are inputs; daily AI commentary is not independent evidence. Without this flag the existing raw-report inventory mode remains available. This change does not enable the flag, change models, or alter hosting configuration.

Employees are reviewed first. If company input needs multiple bounded batches, `batched-v2` performs a final synthesis after every batch succeeds. This final call receives batch results with original evidence, subject to the same input/output, lease, and budget limits. Failed batches keep a partial result; retry reuses successful batches before attempting final synthesis. No text or evidence is silently truncated. If the final input is too large or budget is exhausted, employees remain readable and the run reports the error.

## New/makeup source updates

The explicit **Cập nhật theo dữ liệu mới** action creates an idempotent run from current sources. Successful employee units from the latest accessible completed/partial run in the same period, scope, and department may be reused only when the full settings hash and exact frozen input match. Inputs include daily revision identifiers, report fragments, feedback and any social observations. Changed, failed, inaccessible or incompatible units are not reused. Each reused unit is revalidated, retains provenance, records zero new cost/tokens, and does not call the provider. The company summary is regenerated. Old snapshots are not edited; no automatic paid refresh is introduced.

## Staging acceptance

No database migration or new environment variable is introduced by this change. Deploy both frontend and backend to staging; do not deploy production automatically.

1. Use a real seven-day period with current daily coverage and create a **new** run under v3. Review grouped lines and quantity scopes against the frozen sources.
2. Check that every conclusion is supported, expected outcomes are framed as proposals, and priority has a concrete reason. Valid references do not prove reasoning quality.
3. Check a case with advertising activity but missing conversion data: no invented effectiveness, cause, revenue, assigned owner or deadline.
4. Add a makeup report, complete its daily AI if daily mode is enabled, then explicitly update the period. Only exact unchanged employee inputs should be reused; company synthesis must include the added source.
5. Open the prior run, legacy proposal, history and source tabs. No viewing or employee selection may start an AI call.
6. Test partial employee/batch errors, explicit retry, final-summary failure, and existing budget gates.

Automated checks use deterministic fakes or mocked provider responses. Actual model usefulness requires this real-data acceptance; automated provenance/shape checks alone do not certify it.
