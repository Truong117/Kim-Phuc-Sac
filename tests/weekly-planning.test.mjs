import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import i18next from "i18next";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

test("weekly planning stays route-guarded but is exposed as an authorized work tab", async () => {
  const [app, sidebar, authorization, tabs, taskList, weeklyList] = await Promise.all([
    readSource("src/App.tsx"),
    readSource("src/layout/AppSidebar.tsx"),
    readSource("src/types/authorization.ts"),
    readSource("src/components/tasks/WorkManagementTabs.tsx"),
    readSource("src/pages/Tasks/TaskList.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanList.tsx"),
  ]);

  assert.match(
    app,
    /AuthorizationRoute navigationKey="weekly-plans"[\s\S]*?path="\/weekly-plans"[\s\S]*?path="\/weekly-plans\/new"[\s\S]*?path="\/weekly-plans\/:id"[\s\S]*?path="\/weekly-plans\/:id\/review"/,
  );
  assert.doesNotMatch(sidebar, /navigationKey:\s*"weekly-plans"/);
  assert.match(sidebar, /navigationKey:\s*"tasks"[\s\S]*?activePaths:\s*\["\/weekly-plans"\]/);
  assert.match(tabs, /canNavigate\("weekly-plans"\)/);
  assert.match(tabs, /to:\s*"\/weekly-plans"/);
  assert.match(taskList, /WorkManagementTabs activeTab="tasks"/);
  assert.match(weeklyList, /WorkManagementTabs activeTab="weekly-plans"/);
  assert.match(authorization, /"weekly-plans"/);
  assert.doesNotMatch(app + sidebar + tabs, /role\s*===|OWNER|HR_MANAGER|ADMIN/);
});

test("weekly planning API client exposes explicit analysis and atomic final publication", async () => {
  const service = await readSource("src/services/weeklyPlanningService.ts");

  assert.match(service, /"\/api\/weekly-plans\/import"/);
  assert.match(service, /apiDownload\("\/api\/weekly-plans\/template"\)/);
  assert.match(service, /planAction\(id, "analyze"\)/);
  assert.match(service, /planAction\(id, "approve-and-publish"\)/);
  assert.match(service, /\/employee-reviews/);
  assert.match(service, /\/suggestions\//);
  assert.match(service, /\/comments/);
});

test("weekly entry form uses date-only picker and interaction-gated validation", async () => {
  const form = await readSource("src/components/weekly-plans/WeeklyPlanEntryForm.tsx");

  assert.match(form, /KpsDatePicker/);
  assert.doesNotMatch(form, /type=["']date["']/);
  assert.match(form, /const \[touched, setTouched\]/);
  assert.match(form, /const \[submitted, setSubmitted\]/);
  assert.match(form, /submitted \|\| touched\[field\]/);
  assert.match(form, /compareDateOnly\(values\.due_date, values\.start_date\) < 0/);
  assert.match(form, /minDate=\{weekStart\}/);
  assert.match(form, /maxDate=\{weekEnd\}/);
});

test("weekly UI groups entries by employee and consumes opaque action flags", async () => {
  const [entries, detail, list, upload] = await Promise.all([
    readSource("src/components/weekly-plans/WeeklyPlanEntries.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanDetail.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanList.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanImport.tsx"),
  ]);

  assert.match(entries, /entries\.reduce/);
  assert.match(entries, /entry\.assignee\?\.id/);
  assert.match(detail, /plan\.actions\.can_edit/);
  assert.match(detail, /plan\.actions\.can_review/);
  assert.match(detail, /plan\.actions\.can_analyze/);
  assert.match(detail, /plan\.actions\.can_approve_and_publish/);
  assert.match(detail, /plan\.actions\.can_comment/);
  assert.match(detail, /analyzeWeeklyPlan/);
  assert.match(detail, /approveAndPublishWeeklyPlan/);
  assert.match(detail, /showAssignmentConfirm/);
  assert.match(detail, /plan\.events\.map/);
  assert.match(detail, /commentTarget/);
  assert.match(list, /loading && !result/);
  assert.match(list, /errors\.forbidden/);
  assert.match(list, /references\?\.actions\.can_import/);
  assert.match(upload, /type="file"/);
  assert.match(upload, /accept="\.xlsx,\.docx"/);
  assert.match(upload, /submitted \|\| touched/);
  assert.match(upload, /nextError\.status === 403/);
  assert.match(upload, /nextError\.status === 404/);
  assert.match(upload, /nextError\.status === 409[\s\S]*?nextError\.message/);
});

test("weekly create and review flows return to the authorized weekly list", async () => {
  const [upload, detail, review] = await Promise.all([
    readSource("src/pages/WeeklyPlans/WeeklyPlanImport.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanDetail.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanReview.tsx"),
  ]);

  assert.match(upload, /navigate\("\/weekly-plans"\)/);
  assert.match(detail, /to="\/weekly-plans"/);
  assert.match(detail, /to=\{"\/weekly-plans\/" \+ plan\.id \+ "\/review"\}/);
  assert.match(review, /<WeeklyPlanDetail reviewMode \/>/);
});

test("Vietnamese weekly translations interpolate actual values with single braces", async () => {
  const resource = JSON.parse(await readSource("src/locales/vi/common.json"));
  const instance = i18next.createInstance();
  await instance.init({
    lng: "vi",
    resources: { vi: { common: resource } },
    defaultNS: "common",
    interpolation: { prefix: "{", suffix: "}", escapeValue: false },
  });

  assert.equal(
    instance.t("weeklyPlanning.detail.period", {
      start: "26/10/2026",
      end: "31/10/2026",
    }),
    "Tuần 26/10/2026 – 31/10/2026",
  );
  assert.equal(
    instance.t("weeklyPlanning.detail.commentEmployee", { name: "Nguyễn An" }),
    "Nhân viên: Nguyễn An",
  );
  assert.equal(
    instance.t("weeklyPlanning.review.confirmDescription", {
      employees: 3,
      tasks: 8,
    }),
    "Kế hoạch gồm 3 nhân viên và 8 Task sẽ được tạo.",
  );
});
