import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import i18next from "i18next";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

test("work report pages render an explicit Sunday non-working state", async () => {
  const [newReport, selfReportCard, reportTypes, dashboardTypes, translations] =
    await Promise.all([
      readSource("src/pages/Reports/NewReport.tsx"),
      readSource("src/components/dashboard/SelfReportCard.tsx"),
      readSource("src/types/reports.ts"),
      readSource("src/types/dashboard.ts"),
      readSource("src/locales/vi/common.json"),
    ]);

  assert.match(reportTypes, /is_working_day: boolean/);
  assert.match(dashboardTypes, /"non_working_day"/);
  assert.match(newReport, /today && !today\.is_working_day/);
  assert.ok(
    newReport.indexOf("today && !today.is_working_day") <
      newReport.indexOf("today?.reporting_required === false"),
  );
  assert.match(selfReportCard, /report\.state === "non_working_day"/);

  const messages = JSON.parse(translations);
  const i18n = i18next.createInstance();
  await i18n.init({
    resources: { vi: { common: messages } },
    lng: "vi",
    defaultNS: "common",
    interpolation: { escapeValue: false, prefix: "{", suffix: "}" },
  });

  assert.equal(
    i18n.t("dailyReport.nonWorkingDay.description", {
      date: "11/10/2026",
    }),
    "Ngày 11/10/2026 không thuộc lịch làm việc nên bạn không cần và không thể tạo báo cáo mới.",
  );
  assert.equal(
    i18n.t("overview.selfReport.states.non_working_day"),
    "Ngày nghỉ hằng tuần",
  );
});

test("executive reporting copy explains the Monday to Saturday denominator", async () => {
  const translations = JSON.parse(
    await readSource("src/locales/vi/common.json"),
  );
  const i18n = i18next.createInstance();
  await i18n.init({
    resources: { vi: { common: translations } },
    lng: "vi",
    defaultNS: "common",
    interpolation: { escapeValue: false, prefix: "{", suffix: "}" },
  });

  const message = i18n.t("aiExecutiveDashboard.kpis.reportingMethod", {
    submitted: 3,
    expected: 18,
    workingDays: 6,
  });

  assert.match(message, /3\/18/);
  assert.match(message, /6 ngày làm việc/);
  assert.match(message, /thứ Hai–thứ Bảy/);
});
