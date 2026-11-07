import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");
const moduleUrl = (code) =>
  `data:text/javascript;base64,${Buffer.from(ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText).toString("base64")}`;
const { dailyMailFixture, weeklyMailFixture, dailySummaryFixture } =
  await import(moduleUrl(await read("tests/fixtures/mail-ai.ts")));
const presentationUrl = moduleUrl(
  await read("src/utils/reportPresentation.ts"),
);
const presentation = await import(presentationUrl);
const dateUrl = moduleUrl(await read("src/utils/dateOnly.ts"));
const templates = await import(
  moduleUrl(
    (await read("src/utils/mailTemplates.ts"))
      .replace('"./dateOnly"', JSON.stringify(dateUrl))
      .replace('"./reportPresentation"', JSON.stringify(presentationUrl)),
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

test("daily mail preserves work and qualifiers, omits evidence lists and does not invent unresolved totals", () => {
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
    "Thiết kế: 3 mẫu",
    "Doanh thu (đồng): Cần xác nhận",
    "Hoàn thành thiết kế",
    "Báo cáo AI ngày của Nguyễn Văn An, ngày 05/11/2026",
  ])
    assert.ok(mail.body.includes(text), text);
  assert.doesNotMatch(mail.body, /9000|#\d+/);
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
  assert.doesNotMatch(mail.body, /Ghi nhận:|Căn cứ báo cáo|Bàn giao 3 mẫu/);
  assert.match(
    mail.body,
    /Bản AI tổng hợp tuần cho kỳ 30\/10\/2026 – 05\/11\/2026/,
  );
  assert.match(mail.body, /Nguyễn Văn An — Các nhóm đã hoàn thành/);
  assert.doesNotMatch(mail.body, /#\d+/);
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

test("mail keeps decisions concise and links to their full reasoning in the saved report", () => {
  const source = structuredClone(weeklyMailFixture);
  source.result.recommendations[0] = {
    ...source.result.recommendations[0],
    text: "Đề xuất: Chốt lịch trước thứ Sáu.",
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
  assert.match(mail.body, /Ưu tiên cao: Chốt lịch trước thứ Sáu/);
  assert.match(mail.body, /chưa phải nhiệm vụ hoặc thời hạn đã giao/);
  assert.doesNotMatch(
    mail.body,
    /Đề xuất:|Chờ phản hồi ba ngày|Đối chiếu biên bản/,
  );
  assert.match(mail.body, /analysis=201/);
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
  assert.match(mail.body, /Bản AI tổng hợp chung ngày 05\/11\/2026/);
  assert.match(mail.body, /Nguyễn Văn An — Các nhóm đã hoàn thành/);
  assert.doesNotMatch(mail.body, /#\d+/);
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

test("names in compact mail come only from matching report/item references without exposing quote lists", () => {
  const source = structuredClone(weeklyMailFixture);
  source.source_reports.push({
    id: 25,
    employee_id: 3,
    employee_name: "Phương Anh",
    date: "2026-11-04",
    item_ids: [4],
  });
  source.result.overview[0].evidence.push({
    report_id: 25,
    item_id: 4,
    quote: "Đã tư vấn khách",
  });
  source.result.ongoing[0] = {
    text: "Ghi nhận từ nguồn: Chưa xác nhận bàn giao",
    evidence: [{ report_id: 999, item_id: 4, quote: "Chưa rõ" }],
  };
  for (const kind of ["daily", "weekly"]) {
    const run =
      kind === "daily"
        ? { ...source, period: dailySummaryFixture.period }
        : source;
    const mail =
      kind === "daily"
        ? templates.dailySummaryMailTemplate(
            run,
            "2026-11-05",
            "KPS",
            "https://example.test",
            t,
          )
        : templates.weeklyMailTemplate(run, "KPS", "https://example.test", t);
    assert.match(
      mail.body,
      /Nguyễn Văn An, Phương Anh — Các nhóm đã hoàn thành/,
    );
    assert.match(mail.body, /Chưa xác nhận bàn giao/);
    assert.doesNotMatch(mail.body, /Ghi nhận từ nguồn:|Đã tư vấn khách|#\d+/);
    const headings = [
      "1. Kết quả trong kỳ",
      "2. Công việc đang tiếp tục",
      "3. Vướng mắc cần theo dõi",
      "4. Việc cần xem xét và quyết định",
    ];
    for (let i = 0; i < headings.length; i++) {
      assert.ok(mail.body.includes(headings[i]));
      if (i)
        assert.ok(
          mail.body.indexOf(headings[i - 1]) < mail.body.indexOf(headings[i]),
        );
    }
  }
});

test("legacy prefixes are removed only at the start; qualifiers, internal wording and saved data stay intact", () => {
  const claim = {
    text: "Đề xuất: Đề xuất: Xác nhận duyệt cuối; chưa ghi nhận nghiệm thu.",
    evidence: [{ report_id: 12, item_id: 4, quote: "Bàn giao 3 mẫu" }],
  };
  const before = structuredClone(claim);
  assert.equal(
    presentation.reportClaimText(claim, weeklyMailFixture.source_reports),
    "Nguyễn Văn An — Xác nhận duyệt cuối; chưa ghi nhận nghiệm thu.",
  );
  assert.equal(
    presentation.reportText("Nội dung giữ nguyên: Đề xuất: từ nhân viên"),
    "Nội dung giữ nguyên: Đề xuất: từ nhân viên",
  );
  assert.equal(
    presentation.reportClaimText(
      {
        ...claim,
        evidence: [{ report_id: 12, item_id: 999, quote: "Chưa rõ" }],
      },
      weeklyMailFixture.source_reports,
    ),
    "Xác nhận duyệt cuối; chưa ghi nhận nghiệm thu.",
  );
  assert.equal(
    presentation.reportText("Chưa đủ thông tin: doanh số cả tuần"),
    "Chưa đủ thông tin: doanh số cả tuần",
  );
  assert.deepEqual(claim, before);
});

test("merging duplicate findings retains all employees and preserves distinct decision actions", () => {
  const a = {
    text: "Ghi nhận từ nguồn: Tiếp tục xử lý",
    evidence: [{ report_id: 12, item_id: 4, quote: "A" }],
  };
  const b = {
    text: "Tiếp tục xử lý",
    evidence: [{ report_id: 25, item_id: 4, quote: "B" }],
  };
  const merged = presentation.uniqueReportClaims([a, b]);
  assert.equal(merged.length, 1);
  assert.equal(merged[0].evidence.length, 2);
  assert.equal(a.evidence.length, 1);
  assert.equal(
    presentation.uniqueReportClaims([
      { ...a, action: "Xác nhận duyệt" },
      { ...b, action: "Đối chiếu kết quả" },
    ]).length,
    2,
  );
});
