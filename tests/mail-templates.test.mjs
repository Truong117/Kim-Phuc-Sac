import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");
const moduleUrl = (code) =>
  `data:text/javascript;base64,${Buffer.from(ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText).toString("base64")}`;
const { dailyMailFixture, weeklyMailFixture, dailySummaryFixture } =
  await import(moduleUrl(await read("tests/fixtures/mail-ai.ts")));
const dateUrl = moduleUrl(await read("src/utils/dateOnly.ts"));
const templates = await import(
  moduleUrl(
    (await read("src/utils/mailTemplates.ts")).replace(
      '"./dateOnly"',
      JSON.stringify(dateUrl),
    ),
  )
);
const resources = JSON.parse(await read("src/locales/vi/common.json"));
const t = (key, values = {}) =>
  key
    .split(".")
    .reduce((value, part) => value[part], resources)
    .replace(/\{(\w+)\}/g, (_, name) => String(values[name] ?? `{${name}}`));

test("five built-in templates include editable weekly/monthly plans and an issue escalation without inventing targets", () => {
  assert.deepEqual(templates.mailTemplateIds, [
    "daily",
    "weekly",
    "weekly_plan",
    "monthly_plan",
    "issue",
  ]);
  for (const id of ["weekly_plan", "monthly_plan", "issue"]) {
    const mail = templates.staticMailTemplate(id, "Kim Phục Sắc - Nội bộ", t);
    assert.match(mail.subject, /^\[KPS\]/);
    assert.match(mail.body, /\[Điền/);
    assert.ok(mail.body.endsWith("Kim Phục Sắc - Nội bộ"));
    assert.doesNotMatch(mail.body, /\{fromName\}/);
  }
});

test("daily template preserves AI groups, evidence, comments, recommendations and unresolved quantities without inventing a total", () => {
  const source = structuredClone(dailyMailFixture);
  const before = structuredClone(source);
  const mail = templates.dailyMailTemplate(
    source,
    "KPS",
    "https://internal.example.test",
    t,
    2,
  );
  assert.match(mail.subject, /05\/11\/2026.*Nguyễn Văn An/);
  for (const text of [
    "Đã bàn giao 3 mẫu được duyệt.",
    "Kết quả có căn cứ bàn giao.",
    "Xác nhận lịch triển khai",
    "Bàn giao 3 mẫu",
    "Thiết kế: 3 mẫu",
    "Doanh thu (đồng): Cần xác nhận",
    "AI ngày #101",
    "báo cáo công việc #12",
  ])
    assert.ok(mail.body.includes(text), text);
  assert.doesNotMatch(mail.body, /9000/);
  assert.match(
    mail.body,
    /https:\/\/internal.example.test\/ai\/insights\?view=daily&date=2026-11-05&page=2/,
  );
  assert.deepEqual(source, before);
});

test("weekly template includes every shared AI section and evidence plus a link to the precise saved revision", () => {
  const source = structuredClone(weeklyMailFixture);
  source.department_id = 9;
  const mail = templates.weeklyMailTemplate(
    source,
    "KPS",
    "https://internal.example.test",
    t,
  );
  assert.match(mail.subject, /30\/10\/2026 - 05\/11\/2026/);
  for (const claims of Object.values(source.result))
    for (const claim of claims) assert.ok(mail.body.includes(claim.text));
  assert.match(mail.body, /Bàn giao 3 mẫu/);
  assert.match(mail.body, /Bản tổng hợp AI #201, phiên bản 2/);
  assert.match(mail.body, /analysis=201&end=2026-11-05&days=7&department=9/);
});

test("pending, partial, changed, incompatible and unverified AI sources cannot become report templates", () => {
  for (const status of ["pending", "running", "partial", "failed"]) {
    const daily = structuredClone(dailyMailFixture);
    daily.document.status = status;
    assert.throws(() =>
      templates.dailyMailTemplate(daily, "KPS", "https://example.test", t),
    );
    const weekly = structuredClone(weeklyMailFixture);
    weekly.status = status;
    assert.throws(() =>
      templates.weeklyMailTemplate(weekly, "KPS", "https://example.test", t),
    );
  }
  for (const patch of [
    { source_changed: true },
    { source_changed: null },
    { requires_new_run: true },
    { period: { ...weeklyMailFixture.period, days: 1 } },
    { result: null },
  ])
    assert.throws(() =>
      templates.weeklyMailTemplate(
        { ...weeklyMailFixture, ...patch },
        "KPS",
        "https://example.test",
        t,
      ),
    );
  const daily = structuredClone(dailyMailFixture);
  daily.document.requires_new_document = true;
  assert.throws(() =>
    templates.dailyMailTemplate(daily, "KPS", "https://example.test", t),
  );
  assert.throws(() =>
    templates.dailyMailTemplate(
      { ...dailyMailFixture, source_changed: true },
      "KPS",
      "https://example.test",
      t,
    ),
  );
});

test("mock results are explicitly labeled and long AI text is never silently truncated", () => {
  const weekly = structuredClone(weeklyMailFixture);
  weekly.is_mock = true;
  const text = "Nội dung có căn cứ ".repeat(800);
  weekly.result.overview[0].text = text;
  const mail = templates.weeklyMailTemplate(
    weekly,
    "KPS",
    "https://example.test",
    t,
  );
  assert.match(mail.body, /AI mô phỏng/);
  assert.ok(mail.body.includes(text));
  assert.ok(mail.body.length > 10000);
});

test("weekly mail preserves the structured decision fields introduced by the focused AI reader", () => {
  const source = structuredClone(weeklyMailFixture);
  source.result.recommendations[0] = {
    ...source.result.recommendations[0],
    priority: "high",
    rationale: "Chờ phản hồi ba ngày",
    impact: "Chặn lịch ra mắt",
    action: "Xác nhận người phê duyệt",
    expected_outcome: "Có quyết định rõ ràng",
    verification: "Đối chiếu biên bản",
    decision_needed: "Chọn phương án triển khai",
  };
  const mail = templates.weeklyMailTemplate(
    source,
    "KPS",
    "https://example.test",
    t,
  );
  for (const [key, value] of Object.entries(source.result.recommendations[0]))
    if (!["evidence", "priority"].includes(key))
      assert.ok(mail.body.includes(value));
  assert.match(mail.body, /Ưu tiên cao/);
});

test("the daily aggregate template uses every shared AI section from a one-day analysis and links to that saved run", () => {
  const mail = templates.dailySummaryMailTemplate(
    dailySummaryFixture,
    "2026-11-05",
    "KPS",
    "https://example.test",
    t,
  );
  assert.equal(mail.subject, "[KPS] Báo cáo tổng hợp ngày 05/11/2026");
  assert.match(mail.body, /BÁO CÁO TỔNG HỢP NGÀY 05\/11\/2026/);
  assert.match(mail.body, /Bản AI tổng hợp ngày #301/);
  assert.match(mail.body, /analysis=301&end=2026-11-05&days=1/);
  for (const claims of Object.values(dailySummaryFixture.result))
    for (const claim of claims) assert.ok(mail.body.includes(claim.text));
  assert.doesNotMatch(mail.body, /BÁO CÁO TỔNG HỢP TUẦN/);
});

test("daily aggregates reject another date, a multi-day period, a department-only filter or an incomplete/stale analysis", () => {
  for (const override of [
    { status: "partial" },
    { status: "running" },
    { source_changed: true },
    { source_changed: null },
    { requires_new_run: true },
    { result: null },
    { department_id: 9 },
    { period: { ...dailySummaryFixture.period, days: 7 } },
    { period: { ...dailySummaryFixture.period, start_date: "2026-11-04" } },
  ]) {
    assert.throws(() =>
      templates.dailySummaryMailTemplate(
        { ...dailySummaryFixture, ...override },
        "2026-11-05",
        "KPS",
        "https://example.test",
        t,
      ),
    );
  }
  assert.throws(() =>
    templates.dailySummaryMailTemplate(
      dailySummaryFixture,
      "2026-11-04",
      "KPS",
      "https://example.test",
      t,
    ),
  );
});
