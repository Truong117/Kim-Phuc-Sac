import assert from "node:assert/strict";
import test from "node:test";
import { collectLockedReportChanges } from "../src/utils/lockedReportAmendment.ts";

const baseline = {
  next_day_plan: "Tiếp tục",
  items: [
    {
      id: 11,
      content: "Công việc gốc ",
      result: "Kết quả gốc",
      note: "Ghi chú",
      issue: null,
      status: "COMPLETED",
      sort_order: 0,
      evidence_links: ["https://example.test/evidence"],
    },
    {
      id: 12,
      content: "Mục đã liên kết",
      result: "Kết quả",
      status: "COMPLETED",
      sort_order: 1,
    },
  ],
};

test("locked amendment sends only changed, editable text and leaves protected data out", () => {
  const result = collectLockedReportChanges(
    baseline,
    { editable_item_ids: [11], can_edit_next_day_plan: false },
    {
      items: {
        11: { content: " Công việc gốc ", result: "Kết quả mới", note: " " },
        12: { content: "Không được sửa" },
        13: { content: "Không được thêm" },
      },
      plan: "Không ghi đè kế hoạch được bảo vệ",
    },
  );
  assert.deepEqual(result, {
    changes: { items: [{ id: 11, result: "Kết quả mới", note: null }] },
    hasChanges: true,
    hasInvalidRequiredText: false,
  });
});

test("no-op and whitespace do not become corrections; required changed text cannot be cleared", () => {
  const options = { editable_item_ids: [11], can_edit_next_day_plan: true };
  assert.deepEqual(
    collectLockedReportChanges(baseline, options, {
      items: { 11: { content: "Công việc gốc", note: " Ghi chú " } },
      plan: "\n Tiếp tục \n",
    }),
    { changes: {}, hasChanges: false, hasInvalidRequiredText: false },
  );
  for (const draft of [
    { items: { 11: { content: " " } } },
    { items: { 11: { result: "" } } },
    { items: {}, plan: "" },
  ]) {
    assert.equal(
      collectLockedReportChanges(baseline, options, draft)
        .hasInvalidRequiredText,
      true,
    );
  }
  assert.deepEqual(
    collectLockedReportChanges(baseline, options, {
      items: {},
      plan: "Kế hoạch mới",
    }).changes,
    { next_day_plan: "Kế hoạch mới" },
  );
});
