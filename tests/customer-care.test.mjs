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
