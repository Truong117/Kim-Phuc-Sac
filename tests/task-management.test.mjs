import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import i18next from "i18next";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

test("task routes replace the placeholder and remain guarded by the opaque navigation key", async () => {
  const [app, sidebar, list, detail, tabs] = await Promise.all([
    readSource("src/App.tsx"),
    readSource("src/layout/AppSidebar.tsx"),
    readSource("src/pages/Tasks/TaskList.tsx"),
    readSource("src/pages/Tasks/TaskDetail.tsx"),
    readSource("src/components/tasks/WorkManagementTabs.tsx"),
  ]);

  assert.match(
    app,
    /AuthorizationRoute navigationKey="tasks"[\s\S]*?path="\/tasks"[\s\S]*?path="\/tasks\/new"[\s\S]*?path="\/tasks\/:id"/,
  );
  assert.doesNotMatch(app, /path="\/tasks"[\s\S]{0,120}ModulePlaceholder/);
  assert.match(sidebar, /navigationKey:\s*"tasks"[\s\S]*?matchChildren:\s*true/);
  assert.doesNotMatch(sidebar, /navigationKey:\s*"weekly-plans"/);
  assert.match(list, /WorkManagementTabs activeTab="tasks"/);
  assert.match(tabs, /canNavigate\("weekly-plans"\)/);

  for (const source of [app, sidebar, list, detail, tabs]) {
    assert.doesNotMatch(
      source,
      /\b(?:OWNER|ADMIN|DEPARTMENT_MANAGER|SALES_MANAGER|KPS_SPA_MANAGER)\b/,
    );
  }
});

test("task service uses scoped endpoints and keeps status updates separate from management updates", async () => {
  const service = await readSource("src/services/taskService.ts");

  assert.match(service, /apiRequest<TaskListResponse>\(`\/api\/tasks\?\$\{buildTaskQuery\(query\)\}`/);
  assert.match(service, /"\/api\/tasks\/references"/);
  assert.match(service, /`\/api\/tasks\/\$\{taskId\}\/status`/);
  assert.match(service, /JSON\.stringify\(\{ status \}\)/);
  assert.doesNotMatch(service, /role|permission/i);
});

test("task UI consumes backend action flags and supports loading, empty, error and scoped actions", async () => {
  const [list, create, detail, filters] = await Promise.all([
    readSource("src/pages/Tasks/TaskList.tsx"),
    readSource("src/pages/Tasks/TaskCreate.tsx"),
    readSource("src/pages/Tasks/TaskDetail.tsx"),
    readSource("src/components/tasks/TaskFilters.tsx"),
  ]);

  assert.match(list, /references\.actions\.can_create \|\| result\?\.actions\.can_create/);
  assert.match(list, /isLoading && !result/);
  assert.match(list, /result\?\.data\.length/);
  assert.match(create, /references\?\.actions\.can_create/);
  assert.match(detail, /task\.actions\.can_manage/);
  assert.match(detail, /task\.actions\.can_update_status/);
  assert.match(detail, /updateTaskStatus\(task\.id, selectedStatus\)/);
  assert.match(filters, /overdue/);
  assert.match(filters, /departmentId/);
  assert.match(filters, /locationId/);
});

test("task notification types navigate to the scoped task detail", async () => {
  const [types, dropdown] = await Promise.all([
    readSource("src/types/notifications.ts"),
    readSource("src/components/header/NotificationDropdown.tsx"),
  ]);

  assert.match(types, /"TASK_ASSIGNED"/);
  assert.match(types, /"TASK_REASSIGNED"/);
  assert.match(types, /"TASK"/);
  assert.match(dropdown, /return `\/tasks\/\$\{notification\.reference\.id\}`/);
});

test("Vietnamese task translations use the configured single-brace interpolation", async () => {
  const resource = JSON.parse(await readSource("src/locales/vi/common.json"));
  const instance = i18next.createInstance();
  await instance.init({
    lng: "vi",
    resources: { vi: { common: resource } },
    defaultNS: "common",
    interpolation: { prefix: "{", suffix: "}" },
  });

  assert.equal(
    instance.t("taskManagement.detail.taskId", { id: 42 }),
    "Mã công việc #42",
  );
  assert.equal(
    instance.t("taskManagement.list.pagination", {
      from: 1,
      to: 20,
      total: 27,
    }),
    "Hiển thị 1–20 trong 27 công việc",
  );
});
