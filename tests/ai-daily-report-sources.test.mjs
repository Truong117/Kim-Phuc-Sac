import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import i18next from "i18next";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

test("AI evidence links use scoped source report identity for every result group", async () => {
  const [page, types, translations] = await Promise.all([
    readSource("src/pages/AI/AiDailyInsights.tsx"),
    readSource("src/types/aiDailyReport.ts"),
    readSource("src/locales/vi/common.json"),
  ]);
  const messages = JSON.parse(translations).aiDailyReport;

  assert.match(types, /source_reports:\s*AiDailySourceReport\[\]/);
  assert.match(types, /department_name:\s*string \| null/);
  assert.match(page, /run\?\.source_reports\.find/);
  assert.equal((page.match(/sourceLabel=\{sourceLabel\}/g) ?? []).length, 3);
  assert.match(page, /\{sourceLabel\(id\)\}/);

  const i18n = i18next.createInstance();
  await i18n.init({
    resources: { vi: { common: { aiDailyReport: messages } } },
    lng: "vi",
    defaultNS: "common",
    interpolation: {
      escapeValue: false,
      prefix: "{",
      suffix: "}",
    },
  });

  assert.equal(
    i18n.t("aiDailyReport.sourceLinkDetailed", {
      employee: "Test 01",
      department: "Marketing",
      id: 1,
    }),
    "Test 01 – Marketing – Báo cáo #1",
  );
  assert.equal(
    i18n.t("aiDailyReport.sourceLinkWithoutDepartment", {
      employee: "Test 02",
      id: 2,
    }),
    "Test 02 – Báo cáo #2",
  );
  assert.doesNotMatch(
    i18n.t("aiDailyReport.sourceLinkDetailed", {
      employee: "Test 01",
      department: "Marketing",
      id: 1,
    }),
    /[{}]/,
  );
});
