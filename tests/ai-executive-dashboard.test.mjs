import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import i18next from "i18next";
import ts from "typescript";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

const importTypeScript = async (relativePath) => {
  const source = await readSource(relativePath);
  const transpiled = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.ES2022,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;

  return import(
    `data:text/javascript;base64,${Buffer.from(transpiled).toString("base64")}`
  );
};

const dashboardFixture = (period = "7d", rate = 16.67) => ({
  period: {
    key: period,
    days: period === "7d" ? 7 : 30,
    start_date: "2026-10-04",
    end_date: "2026-10-10",
    timezone: "Asia/Ho_Chi_Minh",
    generated_at: "2026-10-10T19:00:00+07:00",
  },
  data_status: "available",
  totals: {
    reports_submitted: 3,
    work_items: { total: 5, completed: 2, in_progress: 2, blocked: 1 },
    blocked_items: 1,
  },
  reporting: {
    status: rate === null ? "insufficient_data" : "available",
    expected_reporters: 3,
    working_days: 6,
    expected_submissions: 18,
    submitted: 3,
    missing: 15,
    rate_percent: rate,
    basis:
      "current_active_default_memberships_across_monday_to_saturday_working_days",
  },
  daily_trend: [],
  departments: [],
  attention_items: [],
  analysis: {
    status: "available",
    method: "rule_based_v1",
    observations: [],
    disclaimer: "COMPLETED is self-reported.",
  },
});

test("executive dashboard requests 7d and 30d with stale-request protection", async () => {
  const [page, service] = await Promise.all([
    readSource("src/pages/AI/AiExecutiveDashboard.tsx"),
    readSource("src/services/aiExecutiveDashboardService.ts"),
  ]);

  assert.match(page, /useState<AiExecutivePeriodKey>\("7d"\)/);
  assert.match(page, /\["7d", "30d"\] as const/);
  assert.match(page, /getAiExecutiveDashboard\(period, controller\.signal\)/);
  assert.match(page, /controller\.abort\(\)/);
  assert.match(page, /isCurrentRequest = false/);
  assert.match(
    service,
    /\/api\/ai\/executive-dashboard\?period=\$\{encodeURIComponent\(period\)\}/,
  );
});

test("KPI mapping uses backend totals and preserves an unavailable reporting rate", async () => {
  const {
    buildExecutiveKpis,
    buildExecutiveTrendFacts,
    hasExecutiveTrendData,
  } = await importTypeScript("src/utils/aiExecutiveDashboard.ts");

  assert.deepEqual(buildExecutiveKpis(dashboardFixture("7d")), [
    { key: "reports", value: 3 },
    { key: "workItems", value: 5 },
    { key: "completed", value: 2 },
    { key: "inProgress", value: 2 },
    { key: "blocked", value: 1 },
    { key: "reportingRate", value: 16.67, percentage: true },
  ]);
  assert.equal(
    buildExecutiveKpis(dashboardFixture("30d", null)).at(-1).value,
    null,
  );
  assert.equal(
    hasExecutiveTrendData([
      {
        date: "2026-10-10",
        reports_submitted: 0,
        work_items: { total: 0, completed: 0, in_progress: 0, blocked: 0 },
        reporting: { expected: 0, submitted: 0, rate_percent: null },
      },
    ]),
    false,
  );
  assert.equal(
    buildExecutiveTrendFacts([
      {
        date: "2026-10-09",
        reports_submitted: 1,
        work_items: { total: 2, completed: 1, in_progress: 0, blocked: 1 },
        reporting: { expected: 3, submitted: 1, rate_percent: 33.33 },
      },
    ]),
    null,
  );
  assert.deepEqual(
    buildExecutiveTrendFacts([
      {
        date: "2026-10-08",
        reports_submitted: 1,
        work_items: { total: 2, completed: 1, in_progress: 0, blocked: 1 },
        reporting: { expected: 3, submitted: 1, rate_percent: 33.33 },
      },
      {
        date: "2026-10-09",
        reports_submitted: 2,
        work_items: { total: 5, completed: 2, in_progress: 2, blocked: 1 },
        reporting: { expected: 3, submitted: 2, rate_percent: 66.67 },
      },
      {
        date: "2026-10-10",
        reports_submitted: 0,
        work_items: { total: 0, completed: 0, in_progress: 0, blocked: 0 },
        reporting: { expected: 3, submitted: 0, rate_percent: 0 },
      },
    ]),
    {
      observedDays: 2,
      totalDays: 3,
      peakDate: "2026-10-09",
      peakWorkItems: 5,
      blockedItems: 2,
      blockedDays: 2,
    },
  );
});

test("analysis-first hierarchy, empty states and scoped report links are rendered", async () => {
  const [page, kpis, trend, insights, priority, departments, translations] =
    await Promise.all([
      readSource("src/pages/AI/AiExecutiveDashboard.tsx"),
      readSource("src/components/ai/ExecutiveKpiGrid.tsx"),
      readSource("src/components/ai/ExecutiveTrendChart.tsx"),
      readSource("src/components/ai/ExecutiveInsights.tsx"),
      readSource("src/components/ai/ExecutivePriorityIssues.tsx"),
      readSource("src/components/ai/ExecutiveDepartmentTable.tsx"),
      readSource("src/locales/vi/common.json"),
    ]);
  const messages = JSON.parse(translations).aiExecutiveDashboard;

  assert.match(page, /loadError\.status === 403/);
  assert.ok(
    page.indexOf("<ExecutiveInsights") < page.indexOf("<ExecutiveTrendChart"),
  );
  assert.ok(
    page.indexOf("<ExecutiveTrendChart") <
      page.indexOf("<ExecutivePriorityIssues"),
  );
  assert.ok(
    page.indexOf("<ExecutivePriorityIssues") <
      page.indexOf("<ExecutiveDepartmentTable"),
  );
  assert.ok(
    page.indexOf("<ExecutiveDepartmentTable") <
      page.indexOf("<ExecutiveKpiGrid"),
  );
  assert.doesNotMatch(page, /dashboard\.data_status/);
  assert.match(kpis, /value === null/);
  assert.equal(messages.kpis.notEnoughData, "Chưa đủ dữ liệu");
  assert.match(trend, /hasExecutiveTrendData\(rows\)/);
  assert.match(trend, /buildExecutiveTrendFacts\(rows\)/);
  assert.match(insights, /analysis\.observations\.map/);
  assert.match(priority, /items\.map/);
  assert.match(priority, /to=\{`\/reports\/\$\{item\.report_id\}`\}/);
  assert.match(departments, /highestBlocked/);

  const i18n = i18next.createInstance();
  await i18n.init({
    resources: { vi: { common: { aiExecutiveDashboard: messages } } },
    lng: "vi",
    defaultNS: "common",
    interpolation: { escapeValue: false, prefix: "{", suffix: "}" },
  });

  assert.equal(
    i18n.t("aiExecutiveDashboard.insights.sourceDetailed", {
      employee: "Test 01",
      department: "Marketing",
      id: 1,
    }),
    "Test 01 – Marketing – Báo cáo #1",
  );
  assert.equal(
    i18n.t("aiExecutiveDashboard.priorityIssues.sourceWithoutDepartment", {
      employee: "Test 01",
      id: 1,
    }),
    "Test 01 – Báo cáo #1",
  );
  assert.doesNotMatch(JSON.stringify(messages), /\{\{/);
});

test("ai.dashboard has a separate sidebar item and route guard", async () => {
  const [types, sidebar, app, translations] = await Promise.all([
    readSource("src/types/authorization.ts"),
    readSource("src/layout/AppSidebar.tsx"),
    readSource("src/App.tsx"),
    readSource("src/locales/vi/common.json"),
  ]);
  const messages = JSON.parse(translations);

  assert.match(types, /"ai\.dashboard"/);
  assert.match(
    sidebar,
    /navigationKey:\s*"ai\.dashboard"[\s\S]*?path:\s*"\/ai\/dashboard"/,
  );
  assert.match(
    app,
    /AuthorizationRoute navigationKey="ai\.dashboard"[\s\S]*?path="\/ai\/dashboard"/,
  );
  assert.match(
    app,
    /AuthorizationRoute navigationKey="ai\.insights"[\s\S]*?path="\/ai\/insights"/,
  );
  assert.equal(messages.sidebar.items.aiDashboard, "Phân tích điều hành");
  assert.equal(messages.aiExecutiveDashboard.title, "Phân tích điều hành");

  for (const source of [sidebar, app]) {
    assert.doesNotMatch(
      source,
      /\b(?:OWNER|ADMIN|HR_MANAGER|DEPARTMENT_MANAGER)\b/,
    );
  }
});
