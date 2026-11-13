import assert from "node:assert/strict";
import { test } from "node:test";
import { moduleFrom } from "./helpers/load-spa-module.mjs";
const { spaPreviewFixture } = await moduleFrom(
  "src/features/spa/mocks/spaPreview.ts",
);
const { applySpaPreviewCommand: apply } = await moduleFrom(
  "src/features/spa/previewWorkflow.ts",
);
const { getSpaCustomerPhotos, spaPhotoCanRemove } = await moduleFrom(
  "src/features/spa/skinPhotos.ts",
);
const actor = { id: "reviewer", name: "Người xem mẫu" },
  other = { id: "other", name: "Khác" },
  at = "2026-11-13T03:00:00.000Z",
  manager = { manage: true, technicianId: "tech-1" },
  tech = { manage: false, technicianId: "tech-1" };
const fresh = () => structuredClone(spaPreviewFixture);
const booking = (changes = {}) => ({
  kind: "booking",
  id: "SPA-NEW",
  draft: {
    customerId: "customer-new",
    newCustomer: {
      name: "Khách mới mẫu",
      phone: "0900000123",
      note: "Lần đầu",
    },
    date: spaPreviewFixture.date,
    startTime: "16:00",
    serviceKeys: ["skinRecovery"],
    note: "Ghi chú buổi",
    ...changes,
  },
});
const photo = (id = "photo-1", stage = "before") => ({
  id,
  url: `blob:sample-${id}`,
  name: `${id}.jpg`,
  bytes: 1200,
  width: 640,
  height: 480,
  stage,
  caption: "Mẫu",
  source: "library",
});
const add = (photos = [photo()]) => ({
  kind: "photo-add",
  id: "SPA-001",
  taskId: "task-1",
  photos,
});
function success(s, c, access = manager, user = actor) {
  const before = structuredClone(s),
    result = apply(s, c, user, at, access);
  assert.equal(result.error, undefined);
  assert.deepEqual(s, before);
  return result.snapshot;
}
function failure(s, c, error, access = manager, user = actor) {
  const before = structuredClone(s),
    result = apply(s, c, user, at, access);
  assert.equal(result.error, error);
  assert.equal(result.snapshot, s);
  assert.deepEqual(s, before);
}
test("one booking command atomically creates normalized customer and linked appointment", () => {
  const s = fresh(),
    next = success(
      s,
      booking({
        newCustomer: {
          name: " Khách mới mẫu ",
          phone: "+84 900 000 123",
          note: " Lần đầu ",
        },
      }),
    );
  assert.equal(next.customers.length, s.customers.length + 1);
  assert.equal(next.appointments.length, s.appointments.length + 1);
  assert.equal(next.customers.at(-1).phone, "0900000123");
  assert.equal(next.appointments.at(-1).customerId, next.customers.at(-1).id);
  assert.equal(next.appointments.at(-1).tasks[0].status, "unassigned");
});
test("invalid booking never leaves an orphan customer and invalid new customer never creates appointment", () => {
  const s = fresh();
  failure(s, booking({ serviceKeys: [] }), "serviceRequired");
  failure(s, booking({ startTime: "23:30" }), "timeInvalid");
  failure(s, booking({ date: "2026-02-30" }), "dateInvalid");
  failure(
    s,
    booking({ newCustomer: { name: "", phone: "0900000123", note: "" } }),
    "nameRequired",
  );
  failure(
    s,
    booking({ newCustomer: { name: "Tên", phone: "123", note: "" } }),
    "phoneInvalid",
  );
  failure(
    s,
    booking({
      newCustomer: { name: "Tên", phone: "0900000123", note: "x".repeat(1001) },
    }),
    "customerNoteTooLong",
  );
});
test("normalized duplicate phone is rejected; existing-customer path reuses rather than duplicates a profile", () => {
  const s = fresh();
  s.customers[0].phone = "0900000123";
  failure(
    s,
    booking({
      newCustomer: { name: "Tên khác", phone: "+84 900 000 123", note: "" },
    }),
    "phoneDuplicate",
  );
  const next = success(
    s,
    booking({ customerId: s.customers[0].id, newCustomer: undefined }),
  );
  assert.equal(next.customers.length, s.customers.length);
  assert.equal(next.appointments.at(-1).customerId, s.customers[0].id);
  failure(s, booking({ customerId: s.customers[0].id }), "duplicateOperation");
});
test("photos retain stage, customer, service, actor and audit without touching money or service state", () => {
  const s = fresh(),
    next = success(s, add([photo(), photo("photo-2", "after")]));
  assert.equal(next.skinPhotos.length, 2);
  assert.equal(next.skinPhotos[0].customerId, "customer-1");
  assert.equal(next.skinPhotos[0].taskId, "task-1");
  assert.equal(next.skinPhotos[0].createdBy.id, actor.id);
  assert.equal(next.skinPhotos[0].createdAt, at);
  assert.equal(next.appointments[0].audit.at(-1).kind, "photoAdded");
  assert.deepEqual(next.payments, s.payments);
  assert.equal(next.appointments[0].tasks[0].status, "in_progress");
});
test("photo operations require live viewer access and arrived, assigned service", () => {
  const s = fresh();
  failure(s, add(), "photoForbidden", { manage: false, technicianId: null });
  failure(s, add(), "photoForbidden", {
    manage: false,
    technicianId: "tech-2",
  });
  failure(s, { ...add(), id: "SPA-004", taskId: "task-4" }, "photoForbidden");
  const next = success(s, add(), tech);
  assert.equal(next.skinPhotos[0].technicianId, "tech-1");
});
test("photo add rejects an invalid batch, excessive stage count and duplicate IDs atomically", () => {
  let s = fresh();
  failure(
    s,
    add([
      photo(),
      { ...photo("bad"), url: "https://untrusted.test/patient.jpg" },
    ]),
    "photoInvalid",
  );
  failure(s, add([photo(), photo()]), "duplicateOperation");
  failure(
    s,
    add(Array.from({ length: 7 }, (_, i) => photo(`many-${i}`))),
    "photoLimit",
  );
  s = success(s, add(Array.from({ length: 6 }, (_, i) => photo(`six-${i}`))));
  failure(s, add([photo("seventh")]), "photoLimit");
  failure(s, add([{ ...photo("large"), bytes: 2097153 }]), "photoInvalid");
});
test("KTV handover keeps photos available to participants but blocks former technician additions", () => {
  let s = fresh();
  s = success(s, add(), tech);
  s = success(s, {
    kind: "handover-request",
    id: "SPA-001",
    taskId: "task-1",
    technicianId: "tech-1",
    toId: "tech-3",
    reason: "Đổi ca",
  });
  failure(s, add([photo("pending")]), "photoForbidden", tech);
  assert.equal(
    getSpaCustomerPhotos(s, "customer-1", {
      manage: false,
      technicianId: "tech-3",
    }).length,
    1,
  );
  s = success(s, {
    kind: "handover-accept",
    id: "SPA-001",
    taskId: "task-1",
    technicianId: "tech-3",
  });
  failure(s, add([photo("old")]), "photoForbidden", tech);
  assert.equal(getSpaCustomerPhotos(s, "customer-1", tech).length, 1);
  assert.equal(
    getSpaCustomerPhotos(s, "customer-1", {
      manage: false,
      technicianId: "tech-2",
    }).length,
    0,
  );
  s = success(s, add([photo("new")]), {
    manage: false,
    technicianId: "tech-3",
  });
  assert.equal(s.skinPhotos.length, 2);
});
test("removal keeps attribution and reason, denies another KTV and duplicate deletion", () => {
  let s = success(fresh(), add(), tech);
  const remove = {
    kind: "photo-remove",
    id: "SPA-001",
    photoId: "photo-1",
    reason: "Ảnh sai góc",
  };
  failure(s, remove, "photoForbidden", {
    manage: false,
    technicianId: "tech-2",
  });
  failure(s, remove, "photoForbidden", tech, other);
  failure(s, { ...remove, reason: "" }, "reasonRequired", tech);
  assert.equal(
    spaPhotoCanRemove(
      s.skinPhotos[0],
      s.appointments[0].tasks[0],
      tech,
      actor.id,
    ),
    true,
  );
  s = success(s, remove, tech);
  assert.equal(s.skinPhotos[0].url, "");
  assert.equal(s.skinPhotos[0].removedBy.id, actor.id);
  assert.equal(s.skinPhotos[0].removeReason, remove.reason);
  assert.equal(getSpaCustomerPhotos(s, "customer-1", manager).length, 0);
  assert.equal(s.appointments[0].audit.at(-1).kind, "photoRemoved");
  failure(s, remove, "notFound");
});
test("completed KTV session permits after photos, while locked before photos require manager access", () => {
  let s = fresh();
  s.appointments[0].tasks[0].status = "completed";
  failure(s, add(), "photoForbidden", tech);
  s = success(s, add([photo("after", "after")]), tech);
  failure(
    s,
    { kind: "photo-remove", id: "SPA-001", photoId: "after", reason: "Mẫu" },
    "photoForbidden",
    tech,
  );
  s = success(s, {
    kind: "photo-remove",
    id: "SPA-001",
    photoId: "after",
    reason: "Quản lý kiểm tra",
  });
  assert.ok(s.skinPhotos[0].removedAt);
});
