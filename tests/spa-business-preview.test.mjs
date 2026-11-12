import assert from "node:assert/strict";
import { test } from "node:test";
import { moduleFrom } from "./helpers/load-spa-module.mjs";
const { spaPreviewFixture } = await moduleFrom(
  "src/features/spa/mocks/spaPreview.ts",
);
const { applySpaPreviewCommand: apply } = await moduleFrom(
  "src/features/spa/previewWorkflow.ts",
);
const { spaInvoiceBalance, spaReport } = await moduleFrom(
  "src/features/spa/businessSelectors.ts",
);
const { getSpaTechnicianTasks } = await moduleFrom(
  "src/features/spa/selectors.ts",
);
const { spaCsv } = await moduleFrom("src/features/spa/csv.ts");
const actor = { id: "owner", name: "Owner mẫu" },
  at = "2026-11-12T03:00:00.000Z";
const fresh = () => structuredClone(spaPreviewFixture);
const run = (s, c) => {
  const before = structuredClone(s);
  const r = apply(s, c, actor, at);
  assert.deepEqual(s, before, "commands must not mutate input");
  assert.equal(r.error, undefined);
  return r.snapshot;
};
const fail = (s, c, error) => {
  const before = structuredClone(s);
  const r = apply(s, c, actor, at);
  assert.equal(r.error, error);
  assert.equal(r.snapshot, s);
  assert.deepEqual(s, before);
};
const complete = {
  kind: "task-complete",
  id: "SPA-001",
  taskId: "task-1",
  technicianId: "tech-1",
  result: "Kết quả chăm sóc mẫu",
};
test("booking a package does not deduct it; completing deducts once without a second invoice", () => {
  let s = fresh();
  s = run(s, {
    kind: "booking",
    id: "NEW",
    draft: {
      customerId: "customer-1",
      date: s.date,
      startTime: "16:00",
      serviceKeys: ["skinRecovery"],
      packageIds: ["PKG-001"],
      note: "",
    },
  });
  assert.equal(s.packages[0].used, 3);
  s = run(s, complete);
  assert.equal(s.packages[0].used, 4);
  assert.equal(s.packages[0].uses.length, 1);
  assert.equal(
    s.invoices.filter((i) => i.appointmentId === "SPA-001").length,
    0,
  );
  fail(s, complete, "invalidTransition");
});
test("completion validates result, package balance/expiry and owner atomically", () => {
  const s = fresh();
  fail(s, { ...complete, result: " " }, "resultRequired");
  fail(s, { ...complete, technicianId: "tech-2" }, "invalidTransition");
  s.packages[0].used = 10;
  fail(s, complete, "packageEmpty");
  s.packages[0].used = 3;
  s.packages[0].expires = "2026-11-10";
  fail(s, complete, "packageInvalid");
});
test("mixed package/retail service visit invoices only chargeable services after last completion", () => {
  let s = fresh();
  const a = s.appointments[0];
  a.tasks.push({
    ...structuredClone(a.tasks[0]),
    id: "extra",
    packageId: undefined,
    serviceKey: "relaxation",
    serviceName: "Thư giãn",
    unitPrice: 250000,
    startTime: "10:00",
    durationMinutes: 45,
  });
  s = run(s, complete);
  assert.equal(s.invoices.filter((i) => i.appointmentId === a.id).length, 0);
  s = run(s, { ...complete, taskId: "extra" });
  const invoices = s.invoices.filter((i) => i.appointmentId === a.id);
  assert.equal(invoices.length, 1);
  assert.equal(invoices[0].total, 250000);
  assert.equal(invoices[0].lines.length, 1);
  fail(s, { ...complete, taskId: "extra" }, "invalidTransition");
});
test("partial receipts reduce balance, reject overpayments and appear by receipt date in reports", () => {
  let s = fresh();
  const command = {
    kind: "payment",
    id: "pay-new",
    invoiceId: "INV-PKG-001",
    amount: 500000,
    method: "transfer",
    date: "2026-11-11",
  };
  s = run(s, command);
  assert.equal(spaInvoiceBalance(s, command.invoiceId), 4000000);
  assert.equal(spaReport(s, s.date, s.date).collected, 750000);
  assert.equal(spaReport(s, s.date, s.date).billed, 250000);
  assert.equal(spaInvoiceBalance(s, command.invoiceId, "2026-11-10"), 4500000);
  fail(s, command, "duplicateOperation");
  fail(s, { ...command, id: "over", amount: 4000001 }, "amountInvalid");
  for (const amount of [0, -1, 1.2, NaN, Infinity])
    fail(s, { ...command, id: "bad", amount }, "amountInvalid");
  fail(s, { ...command, id: "early", date: "2026-11-09" }, "dateInvalid");
});
test("retail sale posts stock-out and one invoice without inventing a payment", () => {
  let s = fresh();
  const stock = s.products[0].stock,
    payments = s.payments.length;
  const c = {
    kind: "retail-sell",
    id: "retail",
    customerId: "customer-2",
    productId: "product-1",
    quantity: 2,
    date: s.date,
  };
  s = run(s, c);
  assert.equal(s.products[0].stock, stock - 2);
  assert.equal(s.invoices.at(-1).total, 840000);
  assert.equal(s.movements.at(-1).quantity, -2);
  assert.equal(s.payments.length, payments);
  fail(s, { ...c, id: "over", quantity: 1000 }, "stockInsufficient");
  fail(s, c, "duplicateOperation");
});
test("stock movements and expenses require valid positive quantities/amounts and reasons", () => {
  let s = fresh();
  const c = {
    kind: "stock",
    id: "stock",
    productId: "supply-1",
    quantity: 3,
    direction: "out",
    reason: "Sử dụng chăm sóc mẫu",
    date: s.date,
  };
  s = run(s, c);
  assert.equal(s.products.find((p) => p.id === "supply-1").stock, 0);
  fail(s, { ...c, id: "over", quantity: 1 }, "stockInsufficient");
  fail(s, { ...c, id: "empty", reason: "" }, "reasonRequired");
  s = run(s, { ...c, id: "in", direction: "in", quantity: 5 });
  assert.equal(s.products.find((p) => p.id === "supply-1").stock, 5);
  s = run(s, {
    kind: "expense",
    id: "expense",
    amount: 20000,
    reason: "Giặt khăn mẫu",
    method: "cash",
    date: s.date,
  });
  assert.equal(spaReport(s, s.date, s.date).spent, 100000);
});
test("catalog preserves prices for services used in history", () => {
  let s = fresh();
  const service = s.services[0];
  const c = {
    kind: "service-save",
    id: service.key,
    name: "Đổi tên mô tả",
    duration: service.durationMinutes,
    price: service.price,
    group: "Da",
    description: "Mẫu",
    requiresSkinPhoto: true,
  };
  fail(s, { ...c, price: 1 }, "serviceInUse");
  fail(s, { ...c, duration: 90 }, "serviceInUse");
  s = run(s, c);
  assert.equal(s.services[0].name, c.name);
  assert.equal(s.appointments[0].tasks[0].serviceName, service.name);
  s = run(s, { ...c, id: "new", price: 100000, duration: 30 });
  assert.equal(s.services.at(-1).key, "new");
});
test("handover keeps owner until accepted, reserves incoming availability and retains participants", () => {
  let s = fresh();
  const c = {
    kind: "handover-request",
    id: "SPA-001",
    taskId: "task-1",
    technicianId: "tech-1",
    toId: "tech-3",
    reason: "Đổi ca mẫu",
  };
  s = run(s, c);
  assert.equal(s.appointments[0].tasks[0].technicianId, "tech-1");
  assert.equal(
    getSpaTechnicianTasks(s, "tech-3").some((t) => t.id === "task-1"),
    true,
  );
  fail(s, complete, "invalidTransition");
  fail(
    s,
    {
      kind: "handover-accept",
      id: c.id,
      taskId: c.taskId,
      technicianId: "tech-2",
    },
    "invalidTransition",
  );
  fail(
    s,
    {
      kind: "assign",
      id: "SPA-002",
      taskId: "task-2",
      technicianId: "tech-3",
      bedId: "bed-02",
      startTime: "09:30",
    },
    "technicianConflict",
  );
  s = run(s, {
    kind: "handover-accept",
    id: c.id,
    taskId: c.taskId,
    technicianId: "tech-3",
  });
  assert.equal(s.appointments[0].tasks[0].technicianId, "tech-3");
  assert.deepEqual(s.appointments[0].tasks[0].participants, [
    "tech-1",
    "tech-3",
  ]);
  s = run(s, { ...complete, technicianId: "tech-3" });
  assert.equal(
    getSpaTechnicianTasks(s, "tech-1").find((t) => t.id === "task-1").status,
    "completed",
  );
});
test("handover validates conflicts again on acceptance and withdrawal requires reason", () => {
  let s = fresh();
  const c = {
    kind: "handover-request",
    id: "SPA-001",
    taskId: "task-1",
    technicianId: "tech-1",
    toId: "tech-3",
    reason: "Mẫu",
  };
  s = run(s, c);
  fail(
    s,
    { kind: "handover-withdraw", id: c.id, taskId: c.taskId, reason: "" },
    "reasonRequired",
  );
  s.appointments[1].tasks[0].technicianId = "tech-3";
  fail(
    s,
    {
      kind: "handover-accept",
      id: c.id,
      taskId: c.taskId,
      technicianId: "tech-3",
    },
    "technicianConflict",
  );
  s = run(s, {
    kind: "handover-withdraw",
    id: c.id,
    taskId: c.taskId,
    reason: "Giữ nguyên KTV",
  });
  assert.equal(s.appointments[0].tasks[0].handover, undefined);
  assert.equal(s.appointments[0].tasks[0].technicianId, "tech-1");
});
test("settings reject invalid rate or hours excluding an existing visit; bookings respect hours", () => {
  let s = fresh();
  const c = {
    kind: "settings",
    name: "KPS Spa mẫu",
    open: "08:00",
    close: "20:00",
    commission: 8,
  };
  fail(s, { ...c, commission: 101 }, "commissionInvalid");
  fail(s, { ...c, open: "10:00" }, "settingsConflict");
  s = run(s, c);
  assert.equal(s.settings.commission, 8);
  fail(
    s,
    {
      kind: "booking",
      id: "late",
      draft: {
        customerId: "customer-6",
        date: s.date,
        startTime: "19:30",
        serviceKeys: ["skinRecovery"],
        note: "",
      },
    },
    "timeInvalid",
  );
});
test("attendance is unique per staff/day and care results require content", () => {
  let s = fresh();
  const c = { kind: "attendance", staffId: "tech-1", date: s.date };
  s = run(s, c);
  fail(s, c, "duplicateOperation");
  fail(
    s,
    { kind: "care-result", id: "CARE-001", result: "" },
    "resultRequired",
  );
  s = run(s, {
    kind: "care-result",
    id: "CARE-001",
    result: "Đã liên hệ mẫu, khách ổn định",
  });
  assert.equal(s.careTasks[0].done, true);
});
test("package sales validate customers and expiry, create one invoice without reducing sessions", () => {
  let s = fresh();
  const c = {
    kind: "package-sell",
    id: "new-pack",
    customerId: "customer-6",
    serviceKey: "relaxation",
    total: 6,
    price: 1200000,
    expires: "2027-01-01",
    date: s.date,
  };
  fail(s, { ...c, expires: "2026-11-10" }, "dateInvalid");
  s = run(s, c);
  assert.equal(s.packages.at(-1).used, 0);
  assert.equal(s.invoices.at(-1).total, c.price);
  assert.equal(s.payments.length, 2);
  fail(s, c, "duplicateOperation");
});
test("CSV quotes multiline cells and neutralizes spreadsheet formula prefixes", () => {
  const csv = spaCsv([["=cmd()", 'a,"b"', "\n@x", -1, 100]]);
  assert.ok(csv.startsWith('\uFEFF"\'=cmd()"'));
  assert.ok(csv.includes('"a,""b"""'));
  assert.ok(csv.includes('"\'\n@x"'));
  assert.ok(csv.includes('"\'-1"'));
});
