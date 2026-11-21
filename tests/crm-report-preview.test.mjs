import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const source = await readFile(
  new URL("../src/utils/crmReportPreview.ts", import.meta.url),
  "utf8",
);
const compiled = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.ES2022,
    target: ts.ScriptTarget.ES2022,
  },
}).outputText;
const { buildCrmReportPreview } = await import(
  `data:text/javascript;base64,${Buffer.from(compiled).toString("base64")}`
);

const activity = (id, kind, fields = {}) => ({
  id,
  kind,
  customer_code: "KH-7",
  customer_name: "Khách A",
  title: "Tư vấn khách hàng",
  result: "Khách đã hiểu cách dùng",
  services: [{ name: "Tư vấn sản phẩm" }],
  due_on: null,
  ...fields,
});
const task = { id: 51, title: "Chăm sóc khách hàng", revision: 3 };

test("report-only CRM activities preview in the same report sections as server expansion", () => {
  const intake = activity(1, "INTAKE", { title: "Tiếp nhận khách mới" });
  const opportunity = activity(2, "OPPORTUNITY", {
    title: "Mở đợt tư vấn về sản phẩm",
  });
  const consultation = activity(3, "CONSULTATION");
  const preview = buildCrmReportPreview(
    [intake, opportunity, consultation],
    undefined,
    [],
    [],
    "Liên hệ lại",
  );

  assert.deepEqual(
    preview.groups.COMPLETED.rows.map((row) => row.activity.id),
    [1, 3],
  );
  assert.equal(preview.groups.COMPLETED.count, 2);
  assert.deepEqual(preview.groups.IN_PROGRESS.rows[0].activity, opportunity);
  assert.equal(preview.groups.IN_PROGRESS.count, 1);
  assert.equal(preview.groups.BLOCKED.rows.length, 0);
});

test("several CRM consultations appear in one care-task row and retain each result", () => {
  const consultations = [
    activity(1, "CONSULTATION"),
    activity(2, "CONSULTATION", {
      result: "Đã nhận phản hồi",
      services: [{ name: "Theo dõi phản hồi" }],
    }),
  ];
  const before = structuredClone(consultations);
  const linked = buildCrmReportPreview(
    consultations,
    task,
    [],
    [],
    "Liên hệ lại",
  );
  assert.equal(linked.groups.COMPLETED.count, 1);
  assert.equal(linked.groups.COMPLETED.rows.length, 1);
  assert.equal(linked.groups.COMPLETED.rows[0].kind, "care");
  assert.deepEqual(linked.groups.COMPLETED.rows[0].activities, consultations);
  assert.deepEqual(consultations, before);

  const reportOnly = buildCrmReportPreview(
    consultations,
    undefined,
    [],
    [],
    "Liên hệ lại",
  );
  assert.equal(reportOnly.groups.COMPLETED.count, 2);
  assert.equal(reportOnly.groups.COMPLETED.rows.length, 2);
});

test("CRM appends to an existing task in its current section without counting a second task", () => {
  const items = [
    {
      clientId: "manual-task",
      content: task.title,
      result: "Nội dung nhập tay cần giữ lại",
      status: "IN_PROGRESS",
      taskLink: { task_id: task.id },
    },
  ];
  const before = structuredClone(items);
  const preview = buildCrmReportPreview(
    [activity(1, "CONSULTATION")],
    task,
    items,
    [],
    "Liên hệ lại",
  );
  assert.equal(preview.groups.COMPLETED.rows.length, 0);
  assert.equal(preview.groups.IN_PROGRESS.rows.length, 1);
  assert.equal(preview.groups.IN_PROGRESS.count, 0);
  assert.equal(preview.groups.IN_PROGRESS.rows[0].existing, true);
  assert.deepEqual(items, before);
});

test("follow-up plans preview only additional unique lines and preserve the manual plan", () => {
  const manualPlan = ["Công việc nhập tay", "Liên hệ lại KH-7 — Gọi lại"];
  const before = [...manualPlan];
  const preview = buildCrmReportPreview(
    [
      activity(1, "FOLLOW_UP", { title: "Gọi lại" }),
      activity(2, "FOLLOW_UP", { title: "Hỏi kết quả" }),
      activity(3, "FOLLOW_UP", { title: "Hỏi kết quả" }),
    ],
    undefined,
    [],
    manualPlan,
    "Liên hệ lại",
  );
  assert.deepEqual(preview.plans, ["Liên hệ lại KH-7 — Hỏi kết quả"]);
  assert.equal(preview.groups.COMPLETED.count, 0);
  assert.deepEqual(manualPlan, before);
});
