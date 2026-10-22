import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const source = await readFile(
  new URL("../src/services/workReportDraftStorage.ts", import.meta.url),
  "utf8",
);
const transpiled = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.ES2022,
    target: ts.ScriptTarget.ES2022,
  },
}).outputText;
const drafts = await import(
  `data:text/javascript;base64,${Buffer.from(transpiled).toString("base64")}`
);

class MemoryStorage {
  values = new Map();

  get length() {
    return this.values.size;
  }

  key(index) {
    return [...this.values.keys()][index] ?? null;
  }

  getItem(key) {
    return this.values.get(key) ?? null;
  }

  setItem(key, value) {
    this.values.set(key, value);
  }

  removeItem(key) {
    this.values.delete(key);
  }
}

const context = (userId = "7", reportDate = "2026-10-22") => ({
  userId,
  reportDate,
});

const data = (overrides = {}) => ({
  items: [
    {
      clientId: "item-1",
      content: "Kiểm tra website",
      result: "",
      status: "IN_PROGRESS",
      note: "Giữ nguyên ghi chú",
    },
  ],
  quickDrafts: {
    COMPLETED: "Dòng chưa bấm Thêm -> Kết quả",
    IN_PROGRESS: "",
    BLOCKED: "Đang chờ duyệt",
  },
  editorMode: "quick",
  ...overrides,
});

const saveOptions = (expectedRevision = null, overrides = {}) => ({
  writerId: "tab-a",
  expectedRevision,
  serverReportId: 12,
  serverUpdatedAt: "2026-10-22T08:00:00.000Z",
  ...overrides,
});

test("draft storage saves and restores items, pending text, mode and metadata", () => {
  const storage = new MemoryStorage();
  const adapter = new drafts.WorkReportDraftStorage(
    storage,
    () => Date.parse("2026-10-22T09:00:00.000Z"),
    () => "revision-1",
  );

  const saved = adapter.save(context(), data(), saveOptions());
  assert.equal(saved.status, "saved");
  assert.equal(saved.draft.schemaVersion, 1);
  assert.equal(saved.draft.updatedAt, "2026-10-22T09:00:00.000Z");
  assert.equal(saved.draft.data.items[0].clientId, "item-1");
  assert.equal(
    saved.draft.data.quickDrafts.COMPLETED,
    "Dòng chưa bấm Thêm -> Kết quả",
  );

  const loaded = adapter.load(context());
  assert.equal(loaded.status, "found");
  assert.deepEqual(loaded.draft.data, data());
});

test("a fresh storage adapter restores the same draft after a simulated reload", () => {
  const storage = new MemoryStorage();
  const now = () => Date.parse("2026-10-22T09:00:00.000Z");
  const firstPage = new drafts.WorkReportDraftStorage(
    storage,
    now,
    () => "before-reload",
  );
  firstPage.save(context(), data(), saveOptions());

  const reloadedPage = new drafts.WorkReportDraftStorage(storage, now);
  const restored = reloadedPage.load(context());
  assert.equal(restored.status, "found");
  assert.deepEqual(restored.draft.data, data());
});

test("mode switches and duplicate work items round-trip without conversion", () => {
  const storage = new MemoryStorage();
  const adapter = new drafts.WorkReportDraftStorage(
    storage,
    () => Date.parse("2026-10-22T09:00:00.000Z"),
    () => "mode-revision",
  );
  const duplicatedItem = { ...data().items[0], clientId: "item-2" };
  const switched = data({
    items: [...data().items, duplicatedItem],
    editorMode: "detailed",
  });

  adapter.save(context(), switched, saveOptions());
  const restored = adapter.load(context());
  assert.equal(restored.draft.data.editorMode, "detailed");
  assert.equal(restored.draft.data.items.length, 2);
  assert.equal(restored.draft.data.items[0].content, restored.draft.data.items[1].content);
  assert.equal(
    restored.draft.data.quickDrafts.COMPLETED,
    switched.quickDrafts.COMPLETED,
  );
});

test("draft keys isolate users and backend business dates", () => {
  const storage = new MemoryStorage();
  let revision = 0;
  const adapter = new drafts.WorkReportDraftStorage(
    storage,
    () => Date.parse("2026-10-22T09:00:00.000Z"),
    () => `revision-${++revision}`,
  );

  adapter.save(context("7", "2026-10-22"), data(), saveOptions());
  adapter.save(
    context("8", "2026-10-22"),
    data({ editorMode: "detailed" }),
    saveOptions(),
  );
  adapter.save(
    context("7", "2026-10-21"),
    data({ quickDrafts: { COMPLETED: "Hôm qua", IN_PROGRESS: "", BLOCKED: "" } }),
    saveOptions(),
  );

  assert.equal(storage.length, 3);
  assert.equal(adapter.load(context("7", "2026-10-22")).draft.data.editorMode, "quick");
  assert.equal(
    adapter.load(context("8", "2026-10-22")).draft.data.editorMode,
    "detailed",
  );
  assert.equal(
    adapter.load(context("7", "2026-10-21")).draft.data.quickDrafts.COMPLETED,
    "Hôm qua",
  );
});

test("draft with pending quick text only remains meaningful and restorable", () => {
  const pendingOnly = data({
    items: [],
    quickDrafts: {
      COMPLETED: "",
      IN_PROGRESS: "Đang nhập nhưng chưa thêm",
      BLOCKED: "",
    },
  });

  assert.equal(drafts.hasMeaningfulDraftData(pendingOnly), true);
  assert.equal(
    drafts.hasMeaningfulDraftData({
      items: [],
      quickDrafts: { COMPLETED: "", IN_PROGRESS: "", BLOCKED: "" },
      editorMode: "detailed",
    }),
    false,
  );
});

test("corrupt, incompatible and expired drafts fail safely and are cleaned", () => {
  const storage = new MemoryStorage();
  const now = Date.parse("2026-10-22T09:00:00.000Z");
  const adapter = new drafts.WorkReportDraftStorage(storage, () => now);
  const key = drafts.workReportDraftKey(context());

  storage.setItem(key, "{broken");
  assert.equal(adapter.load(context()).status, "invalid");
  assert.equal(storage.getItem(key), null);

  storage.setItem(key, JSON.stringify({ schemaVersion: 999 }));
  assert.equal(adapter.load(context()).status, "invalid");
  assert.equal(storage.getItem(key), null);

  const oldAdapter = new drafts.WorkReportDraftStorage(
    storage,
    () => now - drafts.WORK_REPORT_DRAFT_TTL_MS - 1,
    () => "old-revision",
  );
  oldAdapter.save(context(), data(), saveOptions());
  assert.equal(adapter.load(context()).status, "expired");
  assert.equal(storage.getItem(key), null);
});

test("unavailable local storage never crashes draft operations", () => {
  const unavailable = {
    get length() {
      throw new Error("blocked");
    },
    key() {
      throw new Error("blocked");
    },
    getItem() {
      throw new Error("blocked");
    },
    setItem() {
      throw new Error("quota");
    },
    removeItem() {
      throw new Error("blocked");
    },
  };
  const adapter = new drafts.WorkReportDraftStorage(unavailable);

  assert.equal(adapter.load(context()).status, "unavailable");
  assert.equal(adapter.save(context(), data(), saveOptions()).status, "unavailable");
  assert.equal(adapter.remove(context(), null).status, "unavailable");
  assert.doesNotThrow(() => adapter.cleanupExpired());
});

test("optimistic revisions prevent stale tabs and pending callbacks from overwriting", () => {
  const storage = new MemoryStorage();
  let revision = 0;
  const adapter = new drafts.WorkReportDraftStorage(
    storage,
    () => Date.parse("2026-10-22T09:00:00.000Z") + revision,
    () => `revision-${++revision}`,
  );

  const first = adapter.save(context(), data(), saveOptions());
  const second = adapter.save(
    context(),
    data({ editorMode: "detailed" }),
    saveOptions(first.draft.revision, { writerId: "tab-b" }),
  );
  const stale = adapter.save(
    context(),
    data({ quickDrafts: { COMPLETED: "Cũ", IN_PROGRESS: "", BLOCKED: "" } }),
    saveOptions(first.draft.revision),
  );

  assert.equal(second.status, "saved");
  assert.equal(stale.status, "conflict");
  assert.equal(stale.current.revision, second.draft.revision);

  assert.equal(adapter.remove(context(), second.draft.revision).status, "removed");
  const delayedWrite = adapter.save(
    context(),
    data(),
    saveOptions(second.draft.revision),
  );
  assert.equal(delayedWrite.status, "conflict");
  assert.equal(storage.getItem(drafts.workReportDraftKey(context())), null);
});

test("successful removal targets only the current user and report date", () => {
  const storage = new MemoryStorage();
  let revision = 0;
  const adapter = new drafts.WorkReportDraftStorage(
    storage,
    () => Date.parse("2026-10-22T09:00:00.000Z"),
    () => `exact-${++revision}`,
  );
  const current = adapter.save(context("7", "2026-10-22"), data(), saveOptions());
  adapter.save(context("7", "2026-10-21"), data(), saveOptions());
  adapter.save(context("8", "2026-10-22"), data(), saveOptions());

  assert.equal(
    adapter.remove(
      context("7", "2026-10-22"),
      current.draft.revision,
    ).status,
    "removed",
  );
  assert.equal(adapter.load(context("7", "2026-10-22")).status, "missing");
  assert.equal(adapter.load(context("7", "2026-10-21")).status, "found");
  assert.equal(adapter.load(context("8", "2026-10-22")).status, "found");
});

test("a new draft can be created after the submitted revision is removed", () => {
  const storage = new MemoryStorage();
  let revision = 0;
  const adapter = new drafts.WorkReportDraftStorage(
    storage,
    () => Date.parse("2026-10-22T09:00:00.000Z") + revision,
    () => `next-${++revision}`,
  );
  const submitted = adapter.save(context(), data(), saveOptions());
  adapter.remove(context(), submitted.draft.revision);

  const next = adapter.save(
    context(),
    data({ editorMode: "detailed" }),
    saveOptions(null, { serverUpdatedAt: "2026-10-22T10:00:00.000Z" }),
  );
  assert.equal(next.status, "saved");
  assert.notEqual(next.draft.revision, submitted.draft.revision);
});
