import assert from "node:assert/strict";
import { test } from "node:test";
import {
  feedbackLength,
  readPendingRegeneration,
} from "../src/utils/analysisRegeneration.ts";

test("feedback limit counts Unicode code points consistently with backend", () => {
  assert.equal(feedbackLength("😀".repeat(1000)), 1000);
  assert.equal(feedbackLength("ắ".repeat(1001)), 1001);
});

test("recovery accepts only a valid operation pointer and discards any extra content", () => {
  const key = "12345678-1234-4234-8234-123456789012";
  const storage = {
    getItem: () =>
      JSON.stringify({
        key,
        sourceId: 76,
        review_feedback: "Must never be restored",
      }),
  };
  assert.deepEqual(readPendingRegeneration(storage, "operation"), {
    key,
    sourceId: 76,
  });
  for (const value of [
    null,
    [],
    {},
    { key, sourceId: -1 },
    { key: "bad", sourceId: 1 },
    { key, sourceId: "76" },
  ]) {
    assert.equal(
      readPendingRegeneration(
        { getItem: () => JSON.stringify(value) },
        "operation",
      ),
      null,
    );
  }
  assert.equal(
    readPendingRegeneration({ getItem: () => "broken" }, "operation"),
    null,
  );
  assert.equal(
    readPendingRegeneration(
      {
        getItem: () => {
          throw new Error("denied");
        },
      },
      "operation",
    ),
    null,
  );
});
