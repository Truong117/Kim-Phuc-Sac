import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import {
  buildGoogleDocsAiApplyAllEntries,
  createGoogleDocsAiReviewDrafts,
  submitGoogleDocsAiApplyAll,
  updateGoogleDocsAiReviewDraft,
} from "../src/utils/weeklyPlanGoogleDocsAiReview.ts";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

const passage = (id, sourceKey, tabId = "tab-a") => ({
  id,
  source_key: sourceKey,
  sequence: id,
  kind: "paragraph",
  text: `Passage ${id}`,
  metadata: { tab_id: tabId },
  source_context: null,
  source_group: null,
});

const suggestion = ({
  sourceKeys = ["google-docs:tab-a:1"],
  assigneeId = 101,
  title = "Task A",
  startDate = "2026-11-02",
  dueDate = "2026-11-03",
  priority = "HIGH",
} = {}) => ({
  source_keys: sourceKeys,
  title,
  content: "Content",
  expected_result: "Expected result",
  employee_identifier: null,
  start_date: startDate,
  due_date: dueDate,
  priority,
  note: "Note",
  source_tab: { tab_id: "tab-a", title: "Phương Anh" },
  assignee: {
    id: assigneeId,
    name: `Employee ${assigneeId}`,
    email: `employee-${assigneeId}@example.test`,
  },
  assignment_source: "VERIFIED_GOOGLE_DOCS_TAB_MAPPING",
  verification_fields: ["title"],
  evidence: "Evidence",
  provenance: {
    title: "VERBATIM",
    content: "VERBATIM",
    expected_result: "INFERRED",
    employee_identifier: "SUGGESTED",
    start_date: "VERBATIM",
    due_date: "VERBATIM",
    priority: "INFERRED",
    note: "SUGGESTED",
  },
});

test("Google Docs AI review displays trusted tab assignment and keeps DOCX review separate", async () => {
  const [preview, googleReview, sourceDocument, translationsSource] =
    await Promise.all([
      readSource(
        "src/components/weekly-plans/WeeklyPlanAiExtractionPreview.tsx",
      ),
      readSource(
        "src/components/weekly-plans/WeeklyPlanGoogleDocsAiReview.tsx",
      ),
      readSource("src/components/weekly-plans/WeeklyPlanSourceDocument.tsx"),
      readSource("src/locales/vi/common.json"),
    ]);
  const copy =
    JSON.parse(translationsSource).weeklyPlanning.sourceDocument.aiPreview
      .googleDocs;

  assert.match(preview, /sourceType === "GOOGLE_DOCS"/);
  assert.match(preview, /<WeeklyPlanGoogleDocsAiReview/);
  assert.match(preview, /onReview\(suggestion, passageIds, index\)/);
  assert.match(googleReview, /draft\.sourceTab\?\.title/);
  assert.match(googleReview, /draft\.assignee\?\.name/);
  assert.match(googleReview, /verifiedBadge/);
  assert.match(googleReview, /assigneeLocked/);
  assert.doesNotMatch(googleReview, /employee_identifier/);
  assert.doesNotMatch(
    googleReview,
    /weekly-entry-assignee|assigneePlaceholder/,
  );
  assert.equal(copy.verifiedBadge, "Nhân viên đã xác minh từ mapping");
  assert.match(copy.assignmentAuthority, /không phải do AI suy luận/);
  assert.match(copy.assigneeLocked, /mapping tab đã được HR xác minh/);
  assert.match(sourceDocument, /plan\.actions\.can_ai_extract_source/);
  assert.doesNotMatch(sourceDocument, /mapping.*can_ai_extract_source/i);
});

test("Google Docs draft editing stays local and cannot replace trusted assignment", () => {
  const writes = [];
  const original = createGoogleDocsAiReviewDrafts(
    [suggestion()],
    [passage(11, "google-docs:tab-a:1")],
  );
  const edited = updateGoogleDocsAiReviewDraft(original, 0, {
    title: "Edited locally",
    content: "Edited content",
    assignee: { id: 999, name: "Injected", email: "bad@example.test" },
  });

  assert.equal(edited[0].title, "Edited locally");
  assert.equal(edited[0].content, "Edited content");
  assert.equal(edited[0].assignee.id, 101);
  assert.equal(original[0].title, "Task A");
  assert.equal(writes.length, 0);
});

test("Apply All sends exactly one request with every trusted assignee", async () => {
  const calls = [];
  const drafts = createGoogleDocsAiReviewDrafts(
    [
      suggestion(),
      suggestion({
        sourceKeys: ["google-docs:tab-a:2"],
        assigneeId: 202,
        title: "Task B",
      }),
    ],
    [passage(11, "google-docs:tab-a:1"), passage(12, "google-docs:tab-a:2")],
  );

  const submission = await submitGoogleDocsAiApplyAll(
    async (requestId, entries) => {
      calls.push({ requestId, entries });
      return { succeeded: true };
    },
    "request-1",
    drafts,
    "2026-11-02",
    "2026-11-07",
  );

  assert.equal(submission.submitted, true);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].requestId, "request-1");
  assert.deepEqual(
    calls[0].entries.map((entry) => entry.assignee_id),
    [101, 202],
  );
  assert.deepEqual(
    calls[0].entries.map((entry) => entry.source_passage_ids),
    [[11], [12]],
  );
});

test("missing required fields block Apply All before the request boundary", async () => {
  const calls = [];
  const drafts = createGoogleDocsAiReviewDrafts(
    [suggestion({ title: "", startDate: null, priority: null })],
    [passage(11, "google-docs:tab-a:1")],
  );
  const built = buildGoogleDocsAiApplyAllEntries(
    drafts,
    "2026-11-02",
    "2026-11-07",
  );
  const submission = await submitGoogleDocsAiApplyAll(
    async (...args) => calls.push(args),
    "request-2",
    drafts,
    "2026-11-02",
    "2026-11-07",
  );

  assert.equal(built.entries, null);
  assert.equal(built.errors[0].title, "titleRequired");
  assert.equal(built.errors[0].start_date, "startRequired");
  assert.equal(built.errors[0].priority, "priorityRequired");
  assert.deepEqual(submission, {
    submitted: false,
    errors: built.errors,
  });
  assert.equal(calls.length, 0);
});

test("an apply conflict returns without mutating local review data", async () => {
  const drafts = createGoogleDocsAiReviewDrafts(
    [suggestion()],
    [passage(11, "google-docs:tab-a:1")],
  );
  const snapshot = structuredClone(drafts);
  const submission = await submitGoogleDocsAiApplyAll(
    async () => ({
      succeeded: false,
      validationErrors: {
        "entries.0.assignee_id": ["Mapping changed"],
      },
    }),
    "request-3",
    drafts,
    "2026-11-02",
    "2026-11-07",
  );

  assert.equal(submission.submitted, true);
  assert.equal(submission.result.succeeded, false);
  assert.deepEqual(drafts, snapshot);
});

test("successful Apply All is wired to clear suggestions and close the preview", async () => {
  const [sourceDocument, googleReview] = await Promise.all([
    readSource("src/components/weekly-plans/WeeklyPlanSourceDocument.tsx"),
    readSource("src/components/weekly-plans/WeeklyPlanGoogleDocsAiReview.tsx"),
  ]);

  assert.match(googleReview, /if \(submission\.result\.succeeded\)/);
  assert.match(googleReview, /onApplied\(\)/);
  assert.match(
    sourceDocument,
    /onApplied=\{\(\) => \{[\s\S]*setAiSuggestions\(\[\]\);[\s\S]*setAiPreviewOpen\(false\);/,
  );
});
