import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

const importTypeScriptModule = async (relativePath) => {
  const source = await readSource(relativePath);
  const transpiled = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.ES2022,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;

  return import(
    `data:text/javascript;base64,${Buffer.from(transpiled).toString("base64")}`
  );
};

const validUrl =
  "https://docs.google.com/document/d/KpsGoogleDocument_1234567890AbCdEfGhIj/edit";

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });

  return { promise, resolve, reject };
};

class ManualTimers {
  nextId = 1;
  callbacks = new Map();

  set = (callback) => {
    const id = this.nextId++;
    this.callbacks.set(id, callback);
    return id;
  };

  clear = (id) => {
    this.callbacks.delete(id);
  };

  runAll() {
    const callbacks = [...this.callbacks.values()];
    this.callbacks.clear();
    callbacks.forEach((callback) => callback());
  }
}

class ApiServiceError extends Error {
  constructor(status) {
    super(`API request failed with status ${status}.`);
    this.name = "ApiServiceError";
    this.status = status;
  }
}

const previewState = () => ({
  preview: null,
  error: null,
  loading: false,
  updates: 0,
});

const stateCallbacks = (state) => ({
  onStart: () => {
    state.preview = null;
    state.error = null;
    state.loading = true;
    state.updates++;
  },
  onSuccess: (preview) => {
    state.preview = preview;
    state.updates++;
  },
  onError: (error) => {
    state.error = error;
    state.updates++;
  },
  onTimeout: () => {
    state.error = "timeout";
    state.updates++;
  },
  onSettled: () => {
    state.loading = false;
    state.updates++;
  },
});

const tab = (overrides = {}) => ({
  tab_id: "tab-root",
  title: "Kế hoạch Nguyễn An",
  parent_tab_id: null,
  nesting_level: 0,
  order: 1,
  passage_count: 2,
  character_count: 120,
  source_group: {
    version: 1,
    key: "google-docs-tab:safe-key",
    title: "Kế hoạch Nguyễn An",
    origin: "GOOGLE_DOCS_TAB",
    order: 1,
    identity_verified: false,
    evidence: {
      type: "GOOGLE_DOCS_TAB",
      tab_id: "tab-root",
      parent_tab_id: null,
      nesting_level: 0,
      index: 0,
    },
  },
  ...overrides,
});

test("Google Docs URL validation matches the safe backend reference shape", async () => {
  const { isValidGoogleDocsUrl } = await importTypeScriptModule(
    "src/utils/googleDocsPreview.ts",
  );

  assert.equal(isValidGoogleDocsUrl(validUrl), true);
  assert.equal(
    isValidGoogleDocsUrl(
      "https://docs.google.com/document/d/KpsGoogleDocument_1234567890AbCdEfGhIj",
    ),
    true,
  );
  assert.equal(
    isValidGoogleDocsUrl("https://example.com/document/d/invalid"),
    false,
  );
  assert.equal(
    isValidGoogleDocsUrl("https://docs.google.com/spreadsheets/d/invalid"),
    false,
  );
  assert.equal(isValidGoogleDocsUrl("not-a-url"), false);
  assert.equal(isValidGoogleDocsUrl(""), false);
});

test("preview request uses only document_url and returns the mocked safe API payload", async () => {
  const { requestGoogleDocsPreview } = await importTypeScriptModule(
    "src/services/googleDocsPreviewRequest.ts",
  );
  const calls = [];
  const preview = {
    title: "Kế hoạch tuần",
    revision_id: "revision-1",
    snapshot_sha256: "a".repeat(64),
    identity_verified: false,
    tab_count: 1,
    passage_count: 2,
    character_count: 120,
    tabs: [tab()],
  };
  const mockRequest = async (path, init) => {
    calls.push({ path, init });
    return { data: preview };
  };

  const result = await requestGoogleDocsPreview(mockRequest, `  ${validUrl}  `);

  assert.deepEqual(result, preview);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].path, "/api/weekly-plans/google-docs/preview");
  assert.equal(calls[0].init.method, "POST");
  assert.deepEqual(JSON.parse(calls[0].init.body), { document_url: validUrl });
  assert.equal("organization_id" in JSON.parse(calls[0].init.body), false);
  assert.equal("document_id" in JSON.parse(calls[0].init.body), false);
});

test("tab tree keeps API order, nested tabs, duplicate titles and empty data", async () => {
  const { buildGoogleDocsTabTree } = await importTypeScriptModule(
    "src/utils/googleDocsPreview.ts",
  );
  const tabs = [
    tab({ tab_id: "child", parent_tab_id: "root", nesting_level: 1, order: 2 }),
    tab({ tab_id: "root", title: "Trùng tên", order: 1 }),
    tab({ tab_id: "second-root", title: "Trùng tên", order: 3 }),
  ];

  const tree = buildGoogleDocsTabTree(tabs);

  assert.equal(tree.length, 2);
  assert.equal(tree[0].tab_id, "root");
  assert.equal(tree[0].children.length, 1);
  assert.equal(tree[0].children[0].tab_id, "child");
  assert.equal(tree[1].title, "Trùng tên");
  assert.deepEqual(buildGoogleDocsTabTree([]), []);
});

test("latest request wins when an older request resolves after it", async () => {
  const { GoogleDocsPreviewRequestLifecycle } = await importTypeScriptModule(
    "src/utils/googleDocsPreview.ts",
  );
  const timers = new ManualTimers();
  const lifecycle = new GoogleDocsPreviewRequestLifecycle(
    60_000,
    timers.set,
    timers.clear,
  );
  const state = previewState();
  const callbacks = stateCallbacks(state);
  const requestA = deferred();
  const requestB = deferred();

  const runA = lifecycle.run(() => requestA.promise, callbacks);
  const runB = lifecycle.run(() => requestB.promise, callbacks);
  requestB.resolve("preview-b");
  await runB;
  requestA.resolve("preview-a");
  await runA;

  assert.equal(state.preview, "preview-b");
  assert.equal(state.error, null);
  assert.equal(state.loading, false);
});

test("stale ApiServiceError and finally cannot overwrite a newer successful request", async () => {
  const { GoogleDocsPreviewRequestLifecycle } = await importTypeScriptModule(
    "src/utils/googleDocsPreview.ts",
  );
  const timers = new ManualTimers();
  const lifecycle = new GoogleDocsPreviewRequestLifecycle(
    60_000,
    timers.set,
    timers.clear,
  );
  const state = previewState();
  const callbacks = stateCallbacks(state);
  const requestA = deferred();
  const requestB = deferred();

  const runA = lifecycle.run(() => requestA.promise, callbacks);
  const runB = lifecycle.run(() => requestB.promise, callbacks);
  requestB.resolve("preview-b");
  await runB;
  requestA.reject(new ApiServiceError(503));
  await runA;

  assert.equal(state.preview, "preview-b");
  assert.equal(state.error, null);
  assert.equal(state.loading, false);
});

test("cancel on unmount blocks both late resolve and late reject state updates", async () => {
  const { GoogleDocsPreviewRequestLifecycle } = await importTypeScriptModule(
    "src/utils/googleDocsPreview.ts",
  );

  for (const outcome of ["resolve", "reject"]) {
    const timers = new ManualTimers();
    const lifecycle = new GoogleDocsPreviewRequestLifecycle(
      60_000,
      timers.set,
      timers.clear,
    );
    const state = previewState();
    const pending = deferred();
    const run = lifecycle.run(() => pending.promise, stateCallbacks(state));
    lifecycle.cancel();
    const updatesAfterUnmount = state.updates;

    if (outcome === "resolve") pending.resolve("late-preview");
    else pending.reject(new ApiServiceError(503));
    await run;

    assert.equal(state.updates, updatesAfterUnmount);
    assert.equal(state.preview, null);
    assert.equal(state.error, null);
  }
});

test("timeout aborts and invalidates the request before a late result arrives", async () => {
  const { GoogleDocsPreviewRequestLifecycle } = await importTypeScriptModule(
    "src/utils/googleDocsPreview.ts",
  );
  const timers = new ManualTimers();
  const lifecycle = new GoogleDocsPreviewRequestLifecycle(
    60_000,
    timers.set,
    timers.clear,
  );
  const state = previewState();
  const pending = deferred();
  let requestSignal;
  const run = lifecycle.run((signal) => {
    requestSignal = signal;
    return pending.promise;
  }, stateCallbacks(state));

  timers.runAll();
  assert.equal(requestSignal.aborted, true);
  assert.equal(state.error, "timeout");
  assert.equal(state.loading, false);
  pending.resolve("late-preview");
  await run;

  assert.equal(state.preview, null);
  assert.equal(state.error, "timeout");
});

test("retry success remains stable when the timed-out request later rejects", async () => {
  const { GoogleDocsPreviewRequestLifecycle } = await importTypeScriptModule(
    "src/utils/googleDocsPreview.ts",
  );
  const timers = new ManualTimers();
  const lifecycle = new GoogleDocsPreviewRequestLifecycle(
    60_000,
    timers.set,
    timers.clear,
  );
  const state = previewState();
  const callbacks = stateCallbacks(state);
  const requestA = deferred();
  const requestB = deferred();
  const runA = lifecycle.run(() => requestA.promise, callbacks);
  timers.runAll();

  const runB = lifecycle.run(() => requestB.promise, callbacks);
  requestB.resolve("preview-b");
  await runB;
  requestA.reject(new ApiServiceError(503));
  await runA;

  assert.equal(state.preview, "preview-b");
  assert.equal(state.error, null);
  assert.equal(state.loading, false);
});

test("URL-change cancellation clears old state and keeps loading owned by the active request", async () => {
  const { GoogleDocsPreviewRequestLifecycle } = await importTypeScriptModule(
    "src/utils/googleDocsPreview.ts",
  );
  const timers = new ManualTimers();
  const lifecycle = new GoogleDocsPreviewRequestLifecycle(
    60_000,
    timers.set,
    timers.clear,
  );
  const state = previewState();
  const callbacks = stateCallbacks(state);
  const requestA = deferred();
  const requestB = deferred();
  const runA = lifecycle.run(() => requestA.promise, callbacks);

  lifecycle.cancel();
  state.preview = null;
  state.error = null;
  state.loading = false;
  requestA.resolve("preview-a");
  await runA;
  assert.equal(state.preview, null);

  const lateRequest = deferred();
  const runLate = lifecycle.run(() => lateRequest.promise, callbacks);
  const runB = lifecycle.run(() => requestB.promise, callbacks);
  lateRequest.resolve("stale-preview");
  await runLate;
  assert.equal(state.loading, true);
  requestB.resolve("preview-b");
  await runB;

  assert.equal(state.preview, "preview-b");
  assert.equal(state.loading, false);
});

test("all required HTTP failures map to explicit safe UI states", async () => {
  const { getGoogleDocsPreviewErrorKey } = await importTypeScriptModule(
    "src/utils/googleDocsPreview.ts",
  );

  assert.equal(getGoogleDocsPreviewErrorKey(0), "network");
  assert.equal(getGoogleDocsPreviewErrorKey(401), "sessionExpired");
  assert.equal(getGoogleDocsPreviewErrorKey(403), "forbidden");
  assert.equal(getGoogleDocsPreviewErrorKey(404), "notFound");
  assert.equal(getGoogleDocsPreviewErrorKey(409), "changed");
  assert.equal(getGoogleDocsPreviewErrorKey(422), "validation");
  assert.equal(getGoogleDocsPreviewErrorKey(429), "rateLimited");
  assert.equal(getGoogleDocsPreviewErrorKey(502), "providerResponse");
  assert.equal(getGoogleDocsPreviewErrorKey(503), "unavailable");
});

test("mocked authorization, validation, rate-limit and unavailable failures stay rejected", async () => {
  const [{ requestGoogleDocsPreview }, { getGoogleDocsPreviewErrorKey }] =
    await Promise.all([
      importTypeScriptModule("src/services/googleDocsPreviewRequest.ts"),
      importTypeScriptModule("src/utils/googleDocsPreview.ts"),
    ]);

  for (const [status, expectedKey] of [
    [403, "forbidden"],
    [409, "changed"],
    [422, "validation"],
    [429, "rateLimited"],
    [503, "unavailable"],
  ]) {
    const mockFailure = Object.assign(new Error("safe mocked API failure"), {
      status,
    });
    const mockRequest = async () => {
      throw mockFailure;
    };

    await assert.rejects(
      requestGoogleDocsPreview(mockRequest, validUrl),
      (error) =>
        error === mockFailure &&
        getGoogleDocsPreviewErrorKey(error.status) === expectedKey,
    );
  }
});

test("preview UI clears stale data, exposes loading and never mutates weekly plans", async () => {
  const [panel, importPage, service, request, translationsSource] =
    await Promise.all([
      readSource("src/components/weekly-plans/GoogleDocsPreviewPanel.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanImport.tsx"),
      readSource("src/services/weeklyPlanningService.ts"),
      readSource("src/services/googleDocsPreviewRequest.ts"),
      readSource("src/locales/vi/common.json"),
    ]);
  const translations = JSON.parse(translationsSource);

  assert.match(
    importPage,
    /references\?\.actions\.can_import && <GoogleDocsPreviewPanel/,
  );
  assert.match(panel, /setLoading\(true\)/);
  assert.match(panel, /setLoading\(false\)/);
  assert.match(panel, /setPreview\(null\)/);
  assert.match(panel, /previewWeeklyPlanGoogleDocs/);
  assert.match(panel, /buildGoogleDocsTabTree/);
  assert.match(panel, /node\.children/);
  assert.match(panel, /preview\.identity_verified/);
  assert.match(panel, /errors\.timeout/);
  assert.match(panel, /const REQUEST_TIMEOUT_MS = 60_000/);
  assert.match(panel, /requestLifecycle\.current\?\.cancel\(\)/);
  assert.match(
    service,
    /requestGoogleDocsPreview\(apiRequest, documentUrl, signal\)/,
  );
  assert.doesNotMatch(
    panel + request,
    /localStorage|sessionStorage|dangerouslySetInnerHTML|console\./,
  );
  assert.doesNotMatch(panel, /role\s*===|HR_MANAGER|OWNER|ADMIN|employee_id/);
  assert.doesNotMatch(
    request,
    /organization_id|document_id|\/tasks|\/entries|ai-extract/,
  );
  assert.match(
    translations.weeklyPlanning.googleDocsPreview.errors.unavailable,
    /chưa được cấu hình/,
  );
});
