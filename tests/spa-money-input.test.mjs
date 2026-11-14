import assert from "node:assert/strict";
import { test } from "node:test";
import { moduleFrom } from "./helpers/load-spa-module.mjs";

const { formatSpaMoneyInput: format, editSpaMoneyInput: edit } =
  await moduleFrom("src/features/spa/moneyInput.ts");

test("VND display groups thousands without changing empty, zero or integer precision", () => {
  for (const [raw, display] of [
    ["", ""],
    ["0", "0"],
    ["999", "999"],
    ["1000", "1.000"],
    ["1000000", "1.000.000"],
    ["1000000000", "1.000.000.000"],
    ["9007199254740993", "9.007.199.254.740.993"],
  ])
    assert.equal(format(raw), display);
});
test("typing and pasting grouped VND keep raw digits for the API", () => {
  assert.deepEqual(edit("1000000", 7, ""), { value: "1000000", caret: 9 });
  assert.deepEqual(edit("1.000.000", 9, ""), { value: "1000000", caret: 9 });
  assert.deepEqual(edit("0005", 4, "0"), { value: "5", caret: 1 });
  assert.deepEqual(edit("", 0, "1234", "deleteContentBackward"), {
    value: "",
    caret: 0,
  });
});
test("editing in the middle and deleting either side of a separator keep the caret usable", () => {
  assert.deepEqual(edit("129.345", 3, "12345", "insertText"), {
    value: "129345",
    caret: 3,
  });
  assert.deepEqual(edit("1234", 1, "1234", "deleteContentForward"), {
    value: "134",
    caret: 1,
  });
  assert.deepEqual(edit("1234", 1, "1234", "deleteContentBackward"), {
    value: "234",
    caret: 0,
  });
});
test("invalid currency input cannot silently become a different positive amount", () => {
  for (const text of [
    "-100",
    "+100",
    "1,5",
    "1e3",
    "NaN",
    "Infinity",
    "1000 ₫",
    "abc",
  ])
    assert.equal(edit(text, text.length, "1000"), null);
});
