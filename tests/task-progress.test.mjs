import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";
const source = await readFile(
  new URL("../src/utils/taskProgress.ts", import.meta.url),
  "utf8",
);
const module = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.ES2022,
    target: ts.ScriptTarget.ES2022,
  },
}).outputText;
const {
  namedVideoSuggestions,
  createTaskReportItem,
  previewTaskProgress,
  validTaskLink,
} = await import(
  `data:text/javascript;base64,${Buffer.from(module).toString("base64")}`
);
const task = () => ({
  id: 10,
  title: "Sản xuất video",
  description: null,
  due_date: "2026-11-14",
  quantity_unit: "video",
  target_quantity: 5,
  progress: {
    revision: 2,
    target: 5,
    unit: "video",
    completed: 1,
    counts: { video: 1 },
    percent: 20,
    outputs: [{ id: 42, name: "ABC", unit: "video", completed: true }],
  },
});
test("named video completion handles omitted units, Unicode quotes and duplicate names", () => {
  assert.deepEqual(
    namedVideoSuggestions("Đã hoàn thành video “ABC” và video “DEF”"),
    ["ABC", "DEF"],
  );
  assert.deepEqual(
    namedVideoSuggestions('Hoàn thiện video "ABC", "DEF" và " abc "'),
    ["abc", "DEF"],
  );
  for (const text of [
    "Chưa hoàn thành video “ABC”",
    "Kế hoạch hoàn thành video “ABC”",
    "Đang hoàn thiện video “ABC”",
    "Nhắc lại đã hoàn thành video “ABC”",
    "Đã hoàn thành công việc “ABC”",
  ])
    assert.deepEqual(namedVideoSuggestions(text), []);
});
test("uploading or quoting other videos does not inflate completed production", () => {
  assert.deepEqual(
    namedVideoSuggestions("Đã hoàn thành đăng video “ABC” và video “DEF”"),
    [],
  );
  assert.deepEqual(
    namedVideoSuggestions("Đã hoàn thành video “ABC”. Đăng video “DEF”"),
    ["ABC"],
  );
  assert.deepEqual(
    namedVideoSuggestions("Đã hoàn thành 2 video “ABC” và “DEF”"),
    ["ABC", "DEF"],
  );
});

test("preview counts an existing identity once, preserves unselected products and caps only the percentage", () => {
  const t = task(),
    item = createTaskReportItem(t);
  item.taskLink.pending = true;
  item.taskLink.progress.input.outputs = [
    { name: " abc ", completed: true },
    { name: "DEF", completed: true },
  ];
  const preview = previewTaskProgress(t, item);
  assert.equal(preview.completed, 2);
  assert.equal(preview.percent, 40);
  assert.equal(t.progress.completed, 1);
  item.taskLink.progress.input.outputs = [
    { id: 42, name: "Renamed ABC", completed: false },
    { name: "DEF", completed: true },
  ];
  assert.equal(previewTaskProgress(t, item).completed, 1);
  t.target_quantity = 1;
  assert.equal(
    previewTaskProgress(t, {
      ...item,
      taskLink: {
        ...item.taskLink,
        progress: {
          input: {
            ...item.taskLink.progress.input,
            outputs: [{ name: "DEF", completed: true }],
          },
        },
      },
    }).percent,
    100,
  );
});
test("there is no invented percentage without a goal; restored links use the original result and whole-task KEEP", () => {
  const t = task();
  t.target_quantity = null;
  t.quantity_unit = null;
  const item = createTaskReportItem(t);
  item.taskLink.pending = true;
  item.taskLink.progress.input.outputs = [{ name: "DEF", completed: true }];
  assert.equal(previewTaskProgress(t, item).percent, null);
  assert.equal(item.taskLink.progress.input.status, "KEEP");
  item.result = "Text with appended server summary";
  item.taskLink.progress.source_result = "Original user result";
  assert.equal(createTaskReportItem(t, item).result, "Original user result");
  assert.equal(validTaskLink(item.taskLink), true);
  item.taskLink.progress.input.expected_revision = -1;
  assert.equal(validTaskLink(item.taskLink), false);
});
