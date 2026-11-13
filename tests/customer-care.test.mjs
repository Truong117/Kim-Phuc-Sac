import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";
const source = await readFile(
  new URL("../src/utils/careReports.ts", import.meta.url),
  "utf8",
);
const code = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.ES2022,
    target: ts.ScriptTarget.ES2022,
  },
}).outputText;
const {
  blankCareEntry,
  blankCareWork,
  careSelections,
  careWorkOutputs,
  careWorkText,
  careWorkPayload,
  careWorkError,
  careWorksFromLegacy,
  careEntryOutputs,
  careReportText,
  careEntryPayload,
  validCareContact,
} = await import(
  "data:text/javascript;base64," + Buffer.from(code).toString("base64")
);
test("customer reports keep distinct activities but use one canonical identity across days", () => {
  const entry = {
    ...blankCareEntry(),
    customer_id: 1,
    name: "Khách A",
    activities: [
      { service: "Chăm sóc da", result: "Đã hướng dẫn" },
      { service: "Tư vấn sản phẩm", result: "Hẹn phản hồi" },
    ],
  };
  const task = {
    progress: {
      outputs: [
        {
          id: 30,
          unit: "khách hàng",
          name: "Tên cũ",
          care_customer_id: 1,
          reference: "OLD-001",
          completed: true,
        },
      ],
    },
  };
  const outputs = careEntryOutputs([entry], task);
  assert.equal(outputs.length, 1);
  assert.equal(outputs[0].id, 30);
  assert.equal(outputs[0].reference, "OLD-001");
  assert.equal(outputs[0].care_customer_id, 1);
  assert.match(
    careReportText([entry]),
    /Khách A — Chăm sóc da: Đã hướng dẫn; Tư vấn sản phẩm: Hẹn phản hồi/,
  );
});
test("new customer identity is stable while editing and file labels never leak into the API payload", () => {
  const entry = {
    ...blankCareEntry(),
    new_customer: {
      request_key: crypto.randomUUID(),
      name: "Chị An",
      channel: "Zalo",
      contact: "0901234567",
    },
    attachment_ids: [12],
    attachment_names: { 12: "anh.png" },
    evidence_links: ["", "https://example.test/proof"],
    contact_update: { channel: "", value: "" },
  };
  assert.equal(careEntryOutputs([entry], {}).length, 1);
  assert.equal(
    careEntryOutputs([entry], {})[0].reference,
    careEntryOutputs(
      [{ ...entry, new_customer: { ...entry.new_customer, name: "An" } }],
      {},
    )[0].reference,
  );
  const payload = careEntryPayload([entry])[0];
  assert.equal("attachment_names" in payload, false);
  assert.equal("contact_update" in payload, false);
  assert.deepEqual(payload.evidence_links, ["https://example.test/proof"]);
  assert.notEqual(blankCareEntry().client_key, blankCareEntry().client_key);
});
test("contact field accepts phone or profile and rejects invalid phone and credential URLs", () => {
  for (const text of [
    "",
    "0901234567",
    "+84 901 234 567",
    "https://facebook.com/profile.php?id=123",
    "https://zalo.me/0901234567",
  ])
    assert.equal(validCareContact(text), true, text);
  for (const text of [
    "abc",
    "1234",
    "http://u:p@facebook.com/a",
    "javascript:alert(1)",
    "https://facebook.com",
  ])
    assert.equal(validCareContact(text), false, text);
});

test("work items select multiple services independently and deduplicate customers across work items", () => {
  const a = { client_key: crypto.randomUUID(), customer_id: 1, name: "A" },
    b = { client_key: crypto.randomUUID(), customer_id: 2, name: "B" },
    c = { client_key: crypto.randomUUID(), customer_id: 3, name: "C" };
  const works = [
    {
      ...blankCareWork(),
      services: [{ name: "Chăm sóc da" }, { name: "Theo dõi" }],
      customers: [a, b],
      result: "Đã hướng dẫn",
    },
    {
      ...blankCareWork(),
      services: [{ name: "Tư vấn" }],
      customers: [b, c],
      result: "Đã tư vấn",
    },
  ];
  assert.equal(careSelections(works).length, 3);
  assert.equal(careWorkOutputs(works, {}).length, 3);
  assert.equal(careWorkError(works), null);
  assert.equal(careWorkPayload(works).length, 2);
  assert.match(careWorkText(works), /Công việc 1 — Chăm sóc da; Theo dõi/);
  assert.match(careWorkText(works), /Công việc 2 — Tư vấn/);
  assert.equal(works[0].services.length, 2);
  const repeated = [...works];
  repeated[1] = { ...works[1], services: [{ name: "Chăm sóc da" }] };
  assert.equal(careWorkPayload(repeated).length, 2);
  assert.equal(careWorkError(repeated), null);
});
test("duplicate selections within a work are rejected while customer reuse across works is allowed", () => {
  const a = { client_key: crypto.randomUUID(), customer_id: 1, name: "A" };
  const work = {
    ...blankCareWork(),
    services: [{ name: "Chăm sóc" }],
    customers: [a],
    result: "Đã hỗ trợ",
  };
  assert.equal(
    careWorkError([work, { ...work, client_key: crypto.randomUUID() }]),
    null,
  );
  assert.equal(careWorkError([{ ...work, customers: [a, a] }]), "duplicate");
  assert.equal(
    careWorkError([
      { ...work, services: [{ name: "Chăm sóc" }, { name: " CHĂM  SÓC " }] },
    ]),
    "duplicate",
  );
  assert.equal(careWorkError([{ ...work, services: [] }]), "required");
  assert.equal(careWorkError([{ ...work, customers: [] }]), "required");
});
test("legacy customer activities convert to work items without discarding distinct outcomes or evidence", () => {
  const entry = {
    ...blankCareEntry(),
    customer_id: 1,
    name: "A",
    activities: [
      { service: "Việc A", result: "Kết quả A" },
      { service: "Việc B", result: "Kết quả B" },
    ],
    evidence_links: ["https://example.test/proof"],
    attachment_ids: [11],
  };
  const converted = careWorksFromLegacy([entry]);
  assert.equal(converted.length, 1);
  assert.equal(converted[0].services.length, 2);
  assert.equal(converted[0].customers[0].customer_id, 1);
  assert.match(converted[0].result, /Kết quả A/);
  assert.match(converted[0].result, /Kết quả B/);
  assert.deepEqual(converted[0].attachment_ids, [11]);
  const blank = careWorksFromLegacy([blankCareEntry()])[0];
  assert.equal(blank.customers.length, 0);
  assert.equal(blank.services.length, 0);
});
test("work payload preserves boundaries, service IDs and notes, and strips draft evidence labels", () => {
  const w = {
    ...blankCareWork(),
    services: [{ id: 12, name: "Tư vấn" }],
    customers: [
      {
        client_key: crypto.randomUUID(),
        customer_id: 1,
        name: "A",
        note: " Hẹn riêng ",
      },
    ],
    result: " Đã tư vấn ",
    attachment_ids: [9],
    attachment_names: { 9: "proof.png" },
  };
  const p = careWorkPayload([w])[0];
  assert.equal(p.services[0].id, 12);
  assert.equal(p.customers[0].note, "Hẹn riêng");
  assert.equal(p.result, "Đã tư vấn");
  assert.equal("attachment_names" in p, false);
});
