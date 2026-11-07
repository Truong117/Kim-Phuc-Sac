# Work report AI: server-bound evidence

## Behavior

The focused employee reader, company sections, work grouping, observations, comments,
structured proposals, source footer and all existing tabs remain unchanged.
Only the model-facing evidence contract changes to `work-report-v3.2`.

The provider selects `{report_id, item_id, field}` (`content`, `result`, `note`).
The worker binds these to the already frozen, scoped report fields before existing
output, inventory and numerical validators run. Persisted/API evidence remains
`{report_id, item_id, quote}`. No migration, new dependency or environment variable.
No changes to Daily AI, model selection, automated mail or scheduling.

Raw-report review receives the existing complete work inventory. Daily-document and
company-summary inputs receive `field` annotations on their previously validated
source quotations. Later stages can cite only fields/windows actually supplied to
that call; a short quote is never expanded into unseen source text. Source fields
in a supplied raw inventory remain available. Input planning includes annotations;
the worker and Gateway still enforce size, output-token and budget limits.

Repeated work-group references are validated first, then deduplicated. Overlapping
groups are merged transitively while preserving their texts and titles. Complete
source coverage is still mandatory. A malformed or foreign reference, empty field,
missing work item or unsupported numerical value still fails the unit. No model
result is silently replaced with source text or labeled completed after rejection.
Quantity totals are still calculated by the backend. Daily observations are reused
from the original frozen input, without provider-only annotations.

## Diagnostics and recovery

`AI_INVALID_EVIDENCE` remains the public code. Application warnings additionally
contain a fixed `validation_stage`, and for binding errors an `evidence_reason` and
numeric `evidence_location`. No report/provider payload or exception message is
logged. Reasons distinguish malformed references, foreign/missing items, empty or
unprovided fields, corrupted saved quotes and quantities absent from source.

Failed-unit retry remains explicit and bounded. Completed employee units are kept;
the dependent company summary is regenerated when needed. Rejected Gateway output
is removed from cache and records the actual validation error code.

An earlier `v3.1` / schema `v3.0` run must not execute under new instructions. Old
results/history remain readable, with the existing version warning and new-run flow.
After the user merges staging, promotes main and deploys both applications, create
one new analysis under the current contract using **Tạo / mở phân tích**. If reviewing
the exact old frozen snapshot, use the existing new-analysis version action instead.
Do not reset attempts or rewrite old settings/results in the database. Cross-contract
employee reuse is intentionally blocked; this first run can incur fresh AI calls.

## Verification

- Unit coverage: Vietnamese/multiline source binding, new wire schema, legacy stored
  evidence, duplicate/bridging groups, missing coverage, foreign/empty/malformed
  references, restricted daily/summary windows, long UTF-8 numerical evidence and
  rejection of numbers absent from source.
- Feature coverage: real Gateway HTTP path mocked (employee then summary), raw
  quantities, frozen Daily→Weekly inputs, company batching/consolidation, retry
  preserving successful units, fixed diagnostic metadata and old-contract rejection.
- Existing frontend rendering tests/build verify the unchanged output presentation.

Mocked responses verify software contracts, not the quality of live AI reasoning.
Production screenshots/logs confirmed repeated `AI_INVALID_EVIDENCE`, but old logs
did not retain the rejected payload or distinguish its exact failing reference.
After deployment, validate one previously failing scope, its employee coverage,
company result and new diagnostics before enabling scheduled reports/mail.
