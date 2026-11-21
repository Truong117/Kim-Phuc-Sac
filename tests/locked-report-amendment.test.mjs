import assert from "node:assert/strict";
import test from "node:test";
import {
  collectLockedReportChanges,
  newAmendmentItemErrors,
} from "../src/utils/lockedReportAmendment.ts";

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

const addedItem = {
  key: 1,
  status: "COMPLETED",
  content: " Việc mới ",
  result: " Xong ",
  note: "",
  issue: "",
  evidence: " https://example.test/proof \n\n",
};

test("locked amendment serializes manual additions and only authorized existing deletions", () => {
  const draft = {
    items: { 11: { result: "Bỏ qua vì đang xóa" } },
    deletedItemIds: new Set([11, 12, 13]),
    addedItems: [addedItem],
  };
  assert.deepEqual(
    collectLockedReportChanges(
      baseline,
      {
        editable_item_ids: [11],
        deletable_item_ids: [11],
        can_edit_next_day_plan: false,
        can_add_items: true,
      },
      draft,
    ).changes,
    {
      deleted_item_ids: [11],
      added_items: [
        {
          status: "COMPLETED",
          content: "Việc mới",
          result: "Xong",
          evidence_links: ["https://example.test/proof"],
        },
      ],
    },
  );
  assert.deepEqual(
    collectLockedReportChanges(
      baseline,
      {
        editable_item_ids: [11],
        can_edit_next_day_plan: false,
      },
      draft,
    ).changes,
    { items: [{ id: 11, result: "Bỏ qua vì đang xóa" }] },
  );
});

test("new completed items require result and safe per-item evidence", () => {
  assert.deepEqual(newAmendmentItemErrors(addedItem), {});
  assert.deepEqual(
    newAmendmentItemErrors({
      ...addedItem,
      content: " ",
      result: " ",
      evidence: "\n ",
    }),
    {
      content: "required",
      result: "required",
      evidence: "required",
    },
  );
  for (const evidence of [
    "https://user:secret@example.test",
    "https://example.test/a\nhttps://example.test/a",
    "ftp://example.test/file",
    Array.from(
      { length: 11 },
      (_, index) => `https://example.test/${index}`,
    ).join("\n"),
  ]) {
    assert.deepEqual(newAmendmentItemErrors({ ...addedItem, evidence }), {
      evidence: "invalid",
    });
  }
  for (const status of ["IN_PROGRESS", "BLOCKED"]) {
    assert.deepEqual(
      newAmendmentItemErrors({
        ...addedItem,
        status,
        result: "",
        evidence: "",
      }),
      {},
    );
  }
});
