import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

async function moduleFrom(path) {
  const source = await readFile(new URL(`../${path}`, import.meta.url), "utf8");
  const compiled = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.ESNext,
      target: ts.ScriptTarget.ES2023,
    },
  }).outputText;
  return import(
    `data:text/javascript;base64,${Buffer.from(compiled).toString("base64")}`
  );
}
const { spaPreviewFixture } = await moduleFrom(
  "src/features/spa/mocks/spaPreview.ts",
);
const {
  applySpaPreviewCommand: apply,
  normalizeSpaPhone,
  spaReceptionStage: stage,
} = await moduleFrom("src/features/spa/previewWorkflow.ts");
const { getSpaTechnicianTasks, getSpaOverview } = await moduleFrom(
  "src/features/spa/selectors.ts",
);
const actor = { id: "review-owner", name: "Owner mẫu" };
const otherActor = { id: "review-assistant", name: "Trợ lý mẫu" };
const now = "2026-11-12T03:00:00.000Z";
const fresh = () => structuredClone(spaPreviewFixture);
const draft = (changes = {}) => ({
  customerId: "customer-6",
  date: spaPreviewFixture.date,
  startTime: "09:00",
  serviceKeys: ["skinRecovery"],
  note: "Khách mẫu",
  ...changes,
});
function success(state, command, by = actor) {
  const result = apply(state, command, by, now);
  assert.equal(result.error, undefined);
  return result.snapshot;
}
function booked(changes) {
  return success(fresh(), {
    kind: "booking",
    id: "SPA-007",
    draft: draft(changes),
  });
}
function arrived(changes) {
  return success(booked(changes), { kind: "receive", id: "SPA-007" });
}
function assignment(state, changes = {}) {
  return apply(
    state,
    {
      kind: "assign",
      id: "SPA-007",
      taskId: "SPA-007-service-1",
      technicianId: "tech-3",
      bedId: "bed-03",
      startTime: "09:00",
      ...changes,
    },
    otherActor,
    now,
  );
}
const latest = (state) =>
  state.appointments.find((item) => item.id === "SPA-007");

test("customer creation normalizes phones, rejects duplicates including +84, and propagates name edits", () => {
  assert.equal(normalizeSpaPhone("+84 900 000 001"), "0900000001");
  let state = success(fresh(), {
    kind: "customer",
    id: "new",
    customer: { name: " Khách thử ", phone: "0900 000 001", note: "" },
  });
  const duplicate = apply(
    state,
    {
      kind: "customer",
      id: "other",
      customer: { name: "Trùng số", phone: "+84900000001", note: "" },
    },
    actor,
    now,
  );
  assert.equal(duplicate.error, "phoneDuplicate");
  assert.equal(duplicate.snapshot, state);
  state = success(state, {
    kind: "customer",
    id: "customer-1",
    customer: { name: "Tên mới", phone: "0000000001", note: "" },
  });
  assert.equal(state.appointments[0].customerName, "Tên mới");
});
test("invalid customer fields fail without altering input", () => {
  const state = fresh();
  const before = structuredClone(state);
  for (const [customer, error] of [
    [{ name: "", phone: "0000000000", note: "" }, "nameRequired"],
    [{ name: "Khách", phone: "abc", note: "" }, "phoneInvalid"],
    [
      { name: "Khách", phone: "0000000000", note: "a".repeat(1001) },
      "noteTooLong",
    ],
  ]) {
    assert.equal(
      apply(state, { kind: "customer", id: "new", customer }, actor, now).error,
      error,
    );
    assert.deepEqual(state, before);
  }
});
test("booking creates ordered unassigned services, without assigning resources before check-in", () => {
  const original = fresh();
  const state = success(original, {
    kind: "booking",
    id: "SPA-007",
    draft: draft({ serviceKeys: ["skinRecovery", "relaxation"] }),
  });
  assert.equal(original.appointments.length, 6);
  assert.equal(latest(state).tasks[1].startTime, "10:00");
  assert.ok(
    latest(state).tasks.every(
      (task) =>
        task.status === "unassigned" && !task.technicianId && !task.bedId,
    ),
  );
  assert.equal(latest(state).audit[0].actor.id, actor.id);
  assert.equal(stage(latest(state)), "booked");
  assert.equal(assignment(state).error, "invalidTransition");
});
test("booking rejects missing customer, invalid calendar dates, incomplete services and overnight intervals", () => {
  for (const [changes, error] of [
    [{ customerId: "missing" }, "customerRequired"],
    [{ date: "2026-02-30" }, "dateInvalid"],
    [{ date: "bad" }, "dateInvalid"],
    [{ serviceKeys: [] }, "serviceRequired"],
    [{ serviceKeys: ["skinRecovery", ""] }, "serviceRequired"],
    [{ startTime: "23:30" }, "timeInvalid"],
    [{ startTime: "24:00" }, "timeInvalid"],
  ]) {
    assert.equal(
      apply(
        fresh(),
        { kind: "booking", id: "SPA-007", draft: draft(changes) },
        actor,
        now,
      ).error,
      error,
    );
  }
});
test("customer cannot be booked over existing active service but may book another day", () => {
  assert.equal(
    apply(
      fresh(),
      {
        kind: "booking",
        id: "SPA-007",
        draft: draft({ customerId: "customer-1" }),
      },
      actor,
      now,
    ).error,
    "customerConflict",
  );
  assert.equal(
    latest(booked({ customerId: "customer-1", date: "2026-11-12" })).date,
    "2026-11-12",
  );
});
test("check-in records the current actor and cannot be repeated or applied to cancelled appointments", () => {
  const state = arrived();
  const item = latest(state);
  assert.equal(stage(item), "waiting");
  assert.deepEqual(item.receivedBy, actor);
  assert.equal(item.receivedAt, now);
  assert.equal(
    apply(state, { kind: "receive", id: item.id }, actor, now).error,
    "invalidTransition",
  );
  assert.equal(
    apply(state, { kind: "receive", id: "SPA-006" }, actor, now).error,
    "invalidTransition",
  );
});
test("assignment rejects technician and bed conflicts atomically", () => {
  const state = arrived();
  const before = structuredClone(state);
  assert.equal(
    assignment(state, { technicianId: "tech-1" }).error,
    "technicianConflict",
  );
  assert.equal(assignment(state, { bedId: "bed-01" }).error, "bedConflict");
  assert.equal(
    assignment(state, { technicianId: "missing" }).error,
    "technicianRequired",
  );
  assert.equal(assignment(state, { bedId: "missing" }).error, "bedRequired");
  assert.deepEqual(state, before);
});
test("adjacent intervals and identical resources on different dates do not conflict", () => {
  const minimal = fresh();
  minimal.appointments = minimal.appointments.filter(
    (item) => item.id === "SPA-001",
  );
  let state = success(minimal, {
    kind: "booking",
    id: "SPA-007",
    draft: draft({ startTime: "10:00" }),
  });
  state = success(state, { kind: "receive", id: "SPA-007" });
  assert.equal(
    assignment(state, {
      technicianId: "tech-1",
      bedId: "bed-01",
      startTime: "10:00",
    }).error,
    undefined,
  );
  assert.equal(
    assignment(arrived({ date: "2026-11-12" }), {
      technicianId: "tech-1",
      bedId: "bed-01",
    }).error,
    undefined,
  );
});
test("same-customer services cannot overlap even with different technicians and beds", () => {
  const state = arrived({ serviceKeys: ["skinRecovery", "relaxation"] });
  assert.equal(
    assignment(state, { startTime: "09:30" }).error,
    "customerConflict",
  );
});
test("reassignment removes work from the former technician and retains receiver, dispatcher and history", () => {
  let state = assignment(arrived()).snapshot;
  assert.equal(stage(latest(state)), "assigned");
  assert.equal(getSpaTechnicianTasks(state, "tech-3").length, 1);
  state = assignment(state, {
    technicianId: "tech-2",
    startTime: "11:00",
    bedId: "bed-03",
  }).snapshot;
  assert.equal(getSpaTechnicianTasks(state, "tech-3").length, 0);
  assert.ok(
    getSpaTechnicianTasks(state, "tech-2").some(
      (task) => task.appointmentId === "SPA-007",
    ),
  );
  assert.deepEqual(latest(state).receivedBy, actor);
  assert.deepEqual(latest(state).tasks[0].assignedBy, otherActor);
  assert.deepEqual(
    latest(state).audit.map((item) => item.kind),
    ["created", "arrived", "assigned", "reassigned"],
  );
});
test("cancellation requires a reason, releases resources and excludes work from KTV and overview", () => {
  let state = assignment(arrived()).snapshot;
  assert.equal(
    apply(state, { kind: "cancel", id: "SPA-007", reason: " " }, actor, now)
      .error,
    "cancelReasonRequired",
  );
  const count = getSpaOverview(state).appointments;
  state = success(state, {
    kind: "cancel",
    id: "SPA-007",
    reason: "Khách thay đổi lịch",
  });
  assert.equal(getSpaTechnicianTasks(state, "tech-3").length, 0);
  assert.equal(getSpaOverview(state).appointments, count - 1);
  assert.equal(latest(state).cancelReason, "Khách thay đổi lịch");
  state = success(state, {
    kind: "booking",
    id: "SPA-008",
    draft: draft({ customerId: "customer-5" }),
  });
  state = success(state, { kind: "receive", id: "SPA-008" });
  assert.equal(
    assignment(state, { id: "SPA-008", taskId: "SPA-008-service-1" }).error,
    undefined,
  );
});
test("in-progress and completed service cannot be cancelled or reassigned", () => {
  for (const id of ["SPA-001", "SPA-005"]) {
    const state = fresh();
    const item = state.appointments.find((item) => item.id === id);
    assert.equal(
      apply(state, { kind: "cancel", id, reason: "Thử" }, actor, now).error,
      "invalidTransition",
    );
    assert.equal(
      assignment(state, { id, taskId: item.tasks[0].id }).error,
      "invalidTransition",
    );
  }
});
test("editing a booked appointment resets resources and keeps an audit event; arrived appointment is locked", () => {
  const state = success(fresh(), {
    kind: "booking",
    id: "SPA-004",
    editing: true,
    draft: draft({ customerId: "customer-4", startTime: "15:00" }),
  });
  assert.equal(
    state.appointments.find((item) => item.id === "SPA-004").tasks[0].status,
    "unassigned",
  );
  assert.equal(
    apply(
      arrived(),
      { kind: "booking", id: "SPA-007", editing: true, draft: draft() },
      actor,
      now,
    ).error,
    "invalidTransition",
  );
});
test("technician selector is demo-only and daily task queries exclude other dates and cancelled visits", () => {
  const state = assignment(arrived({ date: "2026-11-12" })).snapshot;
  assert.equal(getSpaTechnicianTasks(state, "tech-3").length, 0);
  assert.equal(
    getSpaTechnicianTasks({ ...state, date: "2026-11-12" }, "tech-3").length,
    1,
  );
  const changed = success(state, { kind: "technician", id: "tech-3" });
  assert.equal(changed.previewTechnician.id, "tech-3");
  assert.equal(state.previewTechnician.id, "tech-1");
});
