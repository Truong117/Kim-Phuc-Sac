import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";
import {
  buildGoogleDocsMappingBatchUpdateInput,
  buildGoogleDocsMappingUpdateInput,
  canSaveGoogleDocsMappingBatch,
  canSaveGoogleDocsMappingDraft,
  confirmGoogleDocsMappingDraft,
  createGoogleDocsMappingDrafts,
  getGoogleDocsMappingConflictKind,
  isGoogleDocsMappingBatchConflictRefreshTrusted,
  isGoogleDocsMappingConflictRefreshTrusted,
  isGoogleDocsMappingSnapshotRegressive,
  markGoogleDocsMappingBatchConflictPending,
  markGoogleDocsMappingConflictPending,
  reconcileGoogleDocsMappingBatchConflict,
  reconcileGoogleDocsMappingBatchSaveSuccess,
  reconcileGoogleDocsMappingConflict,
  reconcileGoogleDocsMappingSaveSuccess,
  reconcileGoogleDocsMappingSnapshot,
  resetGoogleDocsMappingDraftFromServer,
  shouldBlockGoogleDocsMappingWrites,
  shouldApplyGoogleDocsMappingResponse,
  updateGoogleDocsMappingDraft,
} from "../src/utils/googleDocsEmployeeMappingDrafts.ts";
import { spansMultipleGoogleDocsTabs } from "../src/utils/weeklyPlanSourceSelection.ts";
import { requestGoogleDocsEmployeeMappingsBatch } from "../src/services/googleDocsEmployeeMappingRequest.ts";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

const loadApiClient = async () => {
  const source = (await readSource("src/services/apiClient.ts")).replace(
    "import.meta.env.VITE_API_BASE_URL",
    '"/"',
  );
  const transpiled = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.ESNext,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;

  return import(
    `data:text/javascript;base64,${Buffer.from(transpiled).toString("base64")}`
  );
};

const serverTabs = ({
  firstEmployeeId = null,
  firstVersion = 0,
  secondEmployeeId = null,
  secondVersion = 0,
} = {}) => [
  {
    tab_id: "tab-a",
    title: "Kế hoạch",
    status: firstEmployeeId === null ? "UNVERIFIED" : "VERIFIED",
    employee: firstEmployeeId === null ? null : { id: firstEmployeeId },
    version: firstVersion,
  },
  {
    tab_id: "tab-b",
    title: "Kế hoạch",
    status: secondEmployeeId === null ? "UNVERIFIED" : "VERIFIED",
    employee: secondEmployeeId === null ? null : { id: secondEmployeeId },
    version: secondVersion,
  },
];

test("bulk edits stay local and build one payload containing only changed rows", () => {
  const tabs = serverTabs();
  let drafts = createGoogleDocsMappingDrafts(tabs);
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-a", {
    status: "VERIFIED",
    employeeId: "101",
  });
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-b", {
    status: "NOT_APPLICABLE",
    employeeId: "",
  });

  assert.equal(canSaveGoogleDocsMappingBatch(tabs, drafts), true);
  assert.deepEqual(buildGoogleDocsMappingBatchUpdateInput(tabs, drafts), [
    {
      tab_id: "tab-a",
      status: "VERIFIED",
      employee_id: 101,
      expected_version: 0,
    },
    {
      tab_id: "tab-b",
      status: "NOT_APPLICABLE",
      expected_version: 0,
    },
  ]);

  drafts = updateGoogleDocsMappingDraft(drafts, "tab-b", {
    status: "NEEDS_REVIEW",
    employeeId: "",
  });
  assert.equal(drafts["tab-b"].dirty, false);
  assert.deepEqual(buildGoogleDocsMappingBatchUpdateInput(tabs, drafts), [
    {
      tab_id: "tab-a",
      status: "VERIFIED",
      employee_id: 101,
      expected_version: 0,
    },
  ]);

  drafts = updateGoogleDocsMappingDraft(drafts, "tab-a", {
    status: "VERIFIED",
    employeeId: "",
  });
  assert.equal(canSaveGoogleDocsMappingBatch(tabs, drafts), false);
});

test("Save All request sends exactly one batch call with the supplied changed rows", async () => {
  const calls = [];
  const controller = new AbortController();
  const mappings = [
    {
      tab_id: "tab-a",
      status: "VERIFIED",
      employee_id: 101,
      expected_version: 2,
    },
  ];
  const overview = { tabs: [{ tab_id: "tab-a", version: 3 }] };
  const result = await requestGoogleDocsEmployeeMappingsBatch(
    async (path, options) => {
      calls.push({ path, options });
      return { data: overview };
    },
    42,
    mappings,
    controller.signal,
  );

  assert.strictEqual(result, overview);
  assert.equal(calls.length, 1);
  assert.equal(
    calls[0].path,
    "/api/weekly-plans/42/google-docs/employee-mappings/batch",
  );
  assert.equal(calls[0].options.method, "PUT");
  assert.equal(calls[0].options.body, JSON.stringify({ mappings }));
  assert.strictEqual(calls[0].options.signal, controller.signal);
});

const suggestedServerTab = ({
  tabId = "tab-a",
  status = "UNVERIFIED",
  currentEmployeeId = null,
  suggestionEmployeeId = 101,
  basis = "CONFIRMED_EXACT",
  version = 0,
  reason = null,
} = {}) => ({
  tab_id: tabId,
  title: `Tab ${tabId}`,
  status,
  employee: currentEmployeeId === null ? null : { id: currentEmployeeId },
  suggestion:
    suggestionEmployeeId === null
      ? null
      : {
          employee: { id: suggestionEmployeeId },
          basis,
        },
  version,
  reason,
});

test("safe exact historical suggestion prefills a dirty local draft without an API write", () => {
  const apiWrites = [];
  const tabs = [suggestedServerTab()];
  const drafts = createGoogleDocsMappingDrafts(tabs);

  assert.equal(drafts["tab-a"].status, "VERIFIED");
  assert.equal(drafts["tab-a"].employeeId, "101");
  assert.equal(drafts["tab-a"].baseStatus, "NEEDS_REVIEW");
  assert.equal(drafts["tab-a"].baseEmployeeId, "");
  assert.equal(drafts["tab-a"].dirty, true);
  assert.equal(drafts["tab-a"].prefillBasis, "CONFIRMED_EXACT");
  assert.equal(apiWrites.length, 0);
});

test("a safe non-persisted NEEDS_REVIEW tab also receives a local prefill", () => {
  const draft = createGoogleDocsMappingDrafts([
    suggestedServerTab({ status: "NEEDS_REVIEW" }),
  ])["tab-a"];

  assert.equal(draft.status, "VERIFIED");
  assert.equal(draft.employeeId, "101");
  assert.equal(draft.baseStatus, "NEEDS_REVIEW");
  assert.equal(draft.baseVersion, 0);
  assert.equal(draft.dirty, true);
});

test("safe alias suggestion prefills and the UI gives it stronger review attention", async () => {
  const tabs = [suggestedServerTab({ basis: "CONFIRMED_ALIAS" })];
  const drafts = createGoogleDocsMappingDrafts(tabs);
  const [panel, translationsSource] = await Promise.all([
    readSource(
      "src/components/weekly-plans/GoogleDocsEmployeeMappingPanel.tsx",
    ),
    readSource("src/locales/vi/common.json"),
  ]);
  const mapping =
    JSON.parse(translationsSource).weeklyPlanning.googleDocsEmployeeMapping;

  assert.equal(drafts["tab-a"].status, "VERIFIED");
  assert.equal(drafts["tab-a"].employeeId, "101");
  assert.equal(drafts["tab-a"].prefillBasis, "CONFIRMED_ALIAS");
  assert.match(
    panel,
    /draft\.prefillBasis === "CONFIRMED_ALIAS"[\s\S]*border-warning-300/,
  );
  assert.match(mapping.prefill.alias, /vui lòng kiểm tra/);
});

test("null suggestion and ineligible review state never prefill an employee", () => {
  const tabs = [
    suggestedServerTab({ suggestionEmployeeId: null }),
    suggestedServerTab({
      tabId: "tab-b",
      status: "NEEDS_REVIEW",
      suggestionEmployeeId: 101,
      version: 1,
      reason: "EMPLOYEE_INELIGIBLE",
    }),
  ];
  const drafts = createGoogleDocsMappingDrafts(tabs);

  for (const tabId of ["tab-a", "tab-b"]) {
    assert.equal(drafts[tabId].status, "NEEDS_REVIEW");
    assert.equal(drafts[tabId].employeeId, "");
    assert.equal(drafts[tabId].dirty, false);
    assert.equal(drafts[tabId].prefillBasis, undefined);
  }
});

test("a persisted current-plan mapping always wins over a historical suggestion", () => {
  const tabs = [
    suggestedServerTab({
      status: "VERIFIED",
      currentEmployeeId: 202,
      suggestionEmployeeId: 101,
      version: 3,
    }),
  ];
  const draft = createGoogleDocsMappingDrafts(tabs)["tab-a"];

  assert.equal(draft.status, "VERIFIED");
  assert.equal(draft.employeeId, "202");
  assert.equal(draft.baseEmployeeId, "202");
  assert.equal(draft.baseVersion, 3);
  assert.equal(draft.dirty, false);
  assert.equal(draft.prefillBasis, undefined);
});

test("four safe prefills remain local until one Save All batch request", async () => {
  const tabs = [101, 202, 303, 404].map((employeeId, index) =>
    suggestedServerTab({
      tabId: `tab-${index + 1}`,
      suggestionEmployeeId: employeeId,
      basis: index === 3 ? "CONFIRMED_ALIAS" : "CONFIRMED_EXACT",
    }),
  );
  const drafts = createGoogleDocsMappingDrafts(tabs);
  const inputs = buildGoogleDocsMappingBatchUpdateInput(tabs, drafts);
  const calls = [];

  assert.equal(
    Object.values(drafts).every((draft) => draft.dirty),
    true,
  );
  assert.equal(inputs.length, 4);
  assert.deepEqual(
    inputs.map((input) => input.employee_id),
    [101, 202, 303, 404],
  );
  assert.equal(calls.length, 0);

  await requestGoogleDocsEmployeeMappingsBatch(
    async (path, options) => {
      calls.push({ path, options });
      return { data: { tabs: [] } };
    },
    42,
    inputs,
  );

  assert.equal(calls.length, 1);
  assert.equal(calls[0].options.body, JSON.stringify({ mappings: inputs }));
});

test("an operator override replaces a prefilled employee in the final batch", () => {
  const tabs = [suggestedServerTab()];
  const drafts = updateGoogleDocsMappingDraft(
    createGoogleDocsMappingDrafts(tabs),
    "tab-a",
    { employeeId: "909" },
  );

  assert.equal(drafts["tab-a"].prefillBasis, undefined);
  assert.deepEqual(buildGoogleDocsMappingBatchUpdateInput(tabs, drafts), [
    {
      tab_id: "tab-a",
      status: "VERIFIED",
      employee_id: 909,
      expected_version: 0,
    },
  ]);
});

test("reload recomputes an unsaved proposal without treating it as persisted", () => {
  const tabs = [suggestedServerTab()];
  const firstLoad = createGoogleDocsMappingDrafts(tabs);
  const reloaded = createGoogleDocsMappingDrafts(tabs);

  assert.equal(tabs[0].status, "UNVERIFIED");
  assert.equal(tabs[0].employee, null);
  assert.equal(tabs[0].version, 0);
  assert.equal(firstLoad["tab-a"].dirty, true);
  assert.equal(reloaded["tab-a"].dirty, true);
  assert.equal(reloaded["tab-a"].baseStatus, "NEEDS_REVIEW");
  assert.equal(reloaded["tab-a"].baseEmployeeId, "");
});

test("successful Save All replaces proposals with clean persisted mappings", () => {
  const persistedTabs = [101, 202, 303, 404].map((employeeId, index) =>
    suggestedServerTab({
      tabId: `tab-${index + 1}`,
      status: "VERIFIED",
      currentEmployeeId: employeeId,
      suggestionEmployeeId: null,
      version: 1,
    }),
  );
  const drafts = reconcileGoogleDocsMappingBatchSaveSuccess(persistedTabs);

  assert.equal(
    Object.values(drafts).every(
      (draft) =>
        draft.status === "VERIFIED" &&
        !draft.dirty &&
        draft.prefillBasis === undefined,
    ),
    true,
  );
});

test("bulk success clears dirty state and bulk conflict identifies only changed server versions", () => {
  const initial = serverTabs();
  let drafts = createGoogleDocsMappingDrafts(initial);
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-a", {
    status: "VERIFIED",
    employeeId: "101",
  });
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-b", {
    status: "VERIFIED",
    employeeId: "202",
  });
  const inputs = buildGoogleDocsMappingBatchUpdateInput(initial, drafts);
  const pending = markGoogleDocsMappingBatchConflictPending(drafts, inputs);
  assert.equal(pending["tab-a"].conflictRefreshPending, true);
  assert.equal(pending["tab-b"].conflictRefreshPending, true);

  const refreshed = serverTabs({
    firstEmployeeId: 303,
    firstVersion: 1,
    secondVersion: 0,
  });
  assert.equal(
    isGoogleDocsMappingBatchConflictRefreshTrusted(refreshed, inputs),
    true,
  );
  const conflicted = reconcileGoogleDocsMappingBatchConflict(
    pending,
    refreshed,
    inputs,
  );
  assert.equal(conflicted["tab-a"].employeeId, "101");
  assert.equal(conflicted["tab-a"].conflict, true);
  assert.equal(conflicted["tab-b"].employeeId, "202");
  assert.equal(conflicted["tab-b"].conflict, false);
  assert.equal(conflicted["tab-b"].dirty, true);
  assert.equal(
    isGoogleDocsMappingBatchConflictRefreshTrusted(initial, inputs),
    false,
  );

  const successful = reconcileGoogleDocsMappingBatchSaveSuccess(
    serverTabs({
      firstEmployeeId: 101,
      firstVersion: 1,
      secondEmployeeId: 202,
      secondVersion: 1,
    }),
  );
  assert.equal(successful["tab-a"].dirty, false);
  assert.equal(successful["tab-b"].dirty, false);
  assert.equal(successful["tab-a"].baseVersion, 1);
  assert.equal(successful["tab-b"].baseVersion, 1);
});

test("Google Docs source selection detects multiple tabs without blocking one-tab or DOCX selection", () => {
  const passage = (tabId, origin = "GOOGLE_DOCS_TAB") => ({
    source_group: {
      key: `group-${tabId}`,
      origin,
      evidence: { tab_id: tabId },
    },
    source_context: null,
  });

  assert.equal(
    spansMultipleGoogleDocsTabs([passage("tab-a"), passage("tab-b")]),
    true,
  );
  assert.equal(
    spansMultipleGoogleDocsTabs([passage("tab-a"), passage("tab-a")]),
    false,
  );
  assert.equal(
    spansMultipleGoogleDocsTabs([
      passage("section-a", "DOCX_SECTION"),
      passage("section-b", "DOCX_SECTION"),
    ]),
    false,
  );
  assert.equal(
    spansMultipleGoogleDocsTabs([
      {
        source_group: {
          key: "manual-group",
          origin: "MANUAL",
          evidence: {},
        },
        source_context: {
          key: "google-tab-a",
          origin: "GOOGLE_DOCS_TAB",
          evidence: { tab_id: "tab-a" },
        },
      },
      passage("tab-b"),
    ]),
    true,
  );
});

test("saving B preserves dirty A and detects A server version drift", () => {
  let drafts = createGoogleDocsMappingDrafts(
    serverTabs({ firstEmployeeId: 1, firstVersion: 1 }),
  );
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-a", {
    status: "VERIFIED",
    employeeId: "101",
  });
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-b", {
    status: "VERIFIED",
    employeeId: "202",
  });

  const reconciled = reconcileGoogleDocsMappingSaveSuccess(
    drafts,
    serverTabs({
      firstEmployeeId: 2,
      firstVersion: 2,
      secondEmployeeId: 202,
      secondVersion: 1,
    }),
    "tab-b",
  );

  assert.deepEqual(reconciled["tab-a"], {
    status: "VERIFIED",
    employeeId: "101",
    baseStatus: "VERIFIED",
    baseEmployeeId: "1",
    baseVersion: 1,
    dirty: true,
    conflict: true,
    conflictExpectedVersion: null,
    conflictRefreshPending: false,
  });
  assert.deepEqual(reconciled["tab-b"], {
    status: "VERIFIED",
    employeeId: "202",
    baseStatus: "VERIFIED",
    baseEmployeeId: "202",
    baseVersion: 1,
    dirty: false,
    conflict: false,
    conflictExpectedVersion: null,
    conflictRefreshPending: false,
  });
});

test("409 refresh preserves dirty drafts and marks every drifted version", () => {
  let drafts = createGoogleDocsMappingDrafts(
    serverTabs({ firstEmployeeId: 1, firstVersion: 1 }),
  );
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-a", {
    status: "VERIFIED",
    employeeId: "101",
  });
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-b", {
    status: "VERIFIED",
    employeeId: "202",
  });

  const conflicted = reconcileGoogleDocsMappingConflict(
    drafts,
    serverTabs({
      firstEmployeeId: 2,
      firstVersion: 2,
      secondEmployeeId: 303,
      secondVersion: 1,
    }),
    "tab-b",
  );
  assert.equal(conflicted["tab-a"].employeeId, "101");
  assert.equal(conflicted["tab-a"].baseVersion, 1);
  assert.equal(conflicted["tab-a"].dirty, true);
  assert.equal(conflicted["tab-a"].conflict, true);
  assert.equal(conflicted["tab-b"].employeeId, "202");
  assert.equal(conflicted["tab-b"].baseVersion, 0);
  assert.equal(conflicted["tab-b"].dirty, true);
  assert.equal(conflicted["tab-b"].conflict, true);

  const serverChoice = resetGoogleDocsMappingDraftFromServer(
    conflicted,
    serverTabs({
      firstEmployeeId: 2,
      firstVersion: 2,
      secondEmployeeId: 303,
      secondVersion: 1,
    }),
    "tab-b",
  );
  assert.equal(serverChoice["tab-a"].employeeId, "101");
  assert.equal(serverChoice["tab-a"].dirty, true);
  assert.deepEqual(serverChoice["tab-b"], {
    status: "VERIFIED",
    employeeId: "303",
    baseStatus: "VERIFIED",
    baseEmployeeId: "303",
    baseVersion: 1,
    dirty: false,
    conflict: false,
    conflictExpectedVersion: null,
    conflictRefreshPending: false,
  });

  const keptChoice = confirmGoogleDocsMappingDraft(
    conflicted,
    serverTabs({
      firstEmployeeId: 2,
      firstVersion: 2,
      secondEmployeeId: 303,
      secondVersion: 1,
    }),
    "tab-b",
  );
  assert.equal(keptChoice["tab-a"].employeeId, "101");
  assert.equal(keptChoice["tab-b"].employeeId, "202");
  assert.equal(keptChoice["tab-b"].baseVersion, 1);
  assert.equal(keptChoice["tab-b"].dirty, true);
  assert.equal(keptChoice["tab-b"].conflict, false);
});

test("version conflict accepts only a strictly newer affected-tab snapshot", () => {
  let drafts = createGoogleDocsMappingDrafts(
    serverTabs({ firstVersion: 3, secondVersion: 1 }),
  );
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-a", {
    status: "VERIFIED",
    employeeId: "101",
  });
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-b", {
    status: "VERIFIED",
    employeeId: "202",
  });

  const pending = markGoogleDocsMappingConflictPending(drafts, "tab-b", 1);
  assert.equal(pending["tab-b"].employeeId, "202");
  assert.equal(pending["tab-b"].baseVersion, 1);
  assert.equal(pending["tab-b"].dirty, true);
  assert.equal(pending["tab-b"].conflict, true);
  assert.equal(pending["tab-b"].conflictExpectedVersion, 1);
  assert.equal(pending["tab-b"].conflictRefreshPending, true);
  assert.equal(canSaveGoogleDocsMappingDraft(pending["tab-b"]), false);

  const sameVersion = serverTabs({ firstVersion: 3, secondVersion: 1 });
  assert.equal(
    isGoogleDocsMappingConflictRefreshTrusted(sameVersion, "tab-b", 1),
    false,
  );
  const afterFirstStaleRefresh = pending;
  assert.strictEqual(afterFirstStaleRefresh, pending);

  assert.equal(
    isGoogleDocsMappingConflictRefreshTrusted(sameVersion, "tab-b", 1),
    false,
  );
  const afterSecondStaleRefresh = afterFirstStaleRefresh;
  assert.strictEqual(afterSecondStaleRefresh, pending);
  assert.strictEqual(
    resetGoogleDocsMappingDraftFromServer(
      afterSecondStaleRefresh,
      sameVersion,
      "tab-b",
    ),
    afterSecondStaleRefresh,
  );
  assert.strictEqual(
    confirmGoogleDocsMappingDraft(
      afterSecondStaleRefresh,
      sameVersion,
      "tab-b",
    ),
    afterSecondStaleRefresh,
  );
  assert.equal(afterSecondStaleRefresh["tab-a"].employeeId, "101");
  assert.equal(afterSecondStaleRefresh["tab-a"].dirty, true);

  assert.equal(
    isGoogleDocsMappingConflictRefreshTrusted(
      serverTabs({ secondVersion: 0 }),
      "tab-b",
      1,
    ),
    false,
  );
  assert.equal(
    isGoogleDocsMappingConflictRefreshTrusted(
      [{ tab_id: "tab-b" }],
      "tab-b",
      1,
    ),
    false,
  );
  assert.equal(
    isGoogleDocsMappingConflictRefreshTrusted(
      [{ tab_id: "tab-b", version: "2" }],
      "tab-b",
      1,
    ),
    false,
  );

  const newerVersion = serverTabs({ firstVersion: 3, secondVersion: 2 });
  assert.equal(
    isGoogleDocsMappingConflictRefreshTrusted(newerVersion, "tab-b", 1),
    true,
  );
  const refreshed = reconcileGoogleDocsMappingConflict(
    afterSecondStaleRefresh,
    newerVersion,
    "tab-b",
  );
  assert.equal(refreshed["tab-a"].employeeId, "101");
  assert.equal(refreshed["tab-a"].baseVersion, 3);
  assert.equal(refreshed["tab-a"].dirty, true);
  assert.equal(refreshed["tab-b"].employeeId, "202");
  assert.equal(refreshed["tab-b"].baseVersion, 1);
  assert.equal(refreshed["tab-b"].conflict, true);
  assert.equal(refreshed["tab-b"].conflictExpectedVersion, null);
  assert.equal(refreshed["tab-b"].conflictRefreshPending, false);

  const keptChoice = confirmGoogleDocsMappingDraft(
    refreshed,
    newerVersion,
    "tab-b",
  );
  assert.equal(keptChoice["tab-b"].employeeId, "202");
  assert.equal(keptChoice["tab-b"].baseVersion, 2);
  assert.equal(keptChoice["tab-b"].dirty, true);
  assert.equal(keptChoice["tab-b"].conflict, false);
  assert.deepEqual(
    buildGoogleDocsMappingUpdateInput("tab-b", keptChoice["tab-b"]),
    {
      tab_id: "tab-b",
      status: "VERIFIED",
      employee_id: 202,
      expected_version: 2,
    },
  );
  assert.equal(keptChoice["tab-a"].employeeId, "101");
  assert.equal(keptChoice["tab-a"].dirty, true);
});

test("backend 409 codes distinguish version conflicts from locked plans", () => {
  assert.equal(
    getGoogleDocsMappingConflictKind({
      status: 409,
      code: "GOOGLE_DOCS_MAPPING_CONFLICT",
    }),
    "VERSION",
  );
  assert.equal(
    getGoogleDocsMappingConflictKind({
      status: 409,
      code: "GOOGLE_DOCS_MAPPING_LOCKED",
    }),
    "LOCKED",
  );
  assert.equal(
    getGoogleDocsMappingConflictKind({
      status: 409,
      code: "GOOGLE_DOCS_TAB_CHANGED",
    }),
    "OTHER",
  );
  assert.equal(
    getGoogleDocsMappingConflictKind({
      status: 422,
      code: "GOOGLE_DOCS_MAPPING_CONFLICT",
    }),
    null,
  );
  assert.equal(shouldBlockGoogleDocsMappingWrites("VERSION"), false);
  assert.equal(shouldBlockGoogleDocsMappingWrites("LOCKED"), true);
  assert.equal(shouldBlockGoogleDocsMappingWrites("OTHER"), true);
  assert.equal(shouldBlockGoogleDocsMappingWrites(null), false);
});

test("API errors preserve optional backend codes and fail safely", async () => {
  const originalDocument = globalThis.document;
  const originalFetch = globalThis.fetch;
  globalThis.document = { cookie: "" };

  try {
    const { ApiServiceError, apiRequest } = await loadApiClient();

    globalThis.fetch = async () =>
      new Response(
        JSON.stringify({
          code: "GOOGLE_DOCS_MAPPING_CONFLICT",
          message: "Conflict",
        }),
        { status: 409, headers: { "Content-Type": "application/json" } },
      );
    await assert.rejects(apiRequest("/conflict"), (error) => {
      assert.ok(error instanceof ApiServiceError);
      assert.equal(error.status, 409);
      assert.equal(error.code, "GOOGLE_DOCS_MAPPING_CONFLICT");
      assert.equal(error.message, "Conflict");
      return true;
    });

    globalThis.fetch = async () =>
      new Response(JSON.stringify({ message: "Validation failed" }), {
        status: 422,
        headers: { "Content-Type": "application/json" },
      });
    await assert.rejects(apiRequest("/without-code"), (error) => {
      assert.ok(error instanceof ApiServiceError);
      assert.equal(error.status, 422);
      assert.equal(error.code, undefined);
      assert.equal(error.message, "Validation failed");
      return true;
    });

    globalThis.fetch = async () => new Response("not-json", { status: 503 });
    await assert.rejects(apiRequest("/not-json"), (error) => {
      assert.ok(error instanceof ApiServiceError);
      assert.equal(error.status, 503);
      assert.equal(error.code, undefined);
      assert.equal(error.message, "API request failed with status 503.");
      return true;
    });

    globalThis.fetch = async () => {
      throw new TypeError("network unavailable");
    };
    await assert.rejects(apiRequest("/network"), (error) => {
      assert.ok(error instanceof ApiServiceError);
      assert.equal(error.status, 0);
      assert.equal(error.code, undefined);
      return true;
    });
  } finally {
    globalThis.fetch = originalFetch;
    globalThis.document = originalDocument;
  }
});

test("unchanged versions do not create false conflicts or alter base versions", () => {
  let drafts = createGoogleDocsMappingDrafts(
    serverTabs({ firstEmployeeId: 1, firstVersion: 1 }),
  );
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-a", {
    status: "VERIFIED",
    employeeId: "101",
  });

  const reconciled = reconcileGoogleDocsMappingSaveSuccess(
    drafts,
    serverTabs({
      firstEmployeeId: 1,
      firstVersion: 1,
      secondEmployeeId: 202,
      secondVersion: 1,
    }),
    "tab-b",
  );

  assert.equal(reconciled["tab-a"].employeeId, "101");
  assert.equal(reconciled["tab-a"].baseVersion, 1);
  assert.equal(reconciled["tab-a"].dirty, true);
  assert.equal(reconciled["tab-a"].conflict, false);
});

test("an overview refresh preserves dirty values and detects version drift", () => {
  let drafts = createGoogleDocsMappingDrafts(
    serverTabs({ firstEmployeeId: 1, firstVersion: 1 }),
  );
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-a", {
    status: "VERIFIED",
    employeeId: "101",
  });

  const reconciled = reconcileGoogleDocsMappingSnapshot(
    drafts,
    serverTabs({ firstEmployeeId: 2, firstVersion: 2 }),
  );

  assert.equal(reconciled["tab-a"].employeeId, "101");
  assert.equal(reconciled["tab-a"].baseVersion, 1);
  assert.equal(reconciled["tab-a"].dirty, true);
  assert.equal(reconciled["tab-a"].conflict, true);
});

test("new mapping version zero conflicts when another HR creates version one", () => {
  let drafts = createGoogleDocsMappingDrafts(serverTabs());
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-a", {
    status: "VERIFIED",
    employeeId: "101",
  });

  const reconciled = reconcileGoogleDocsMappingSaveSuccess(
    drafts,
    serverTabs({ firstEmployeeId: 2, firstVersion: 1 }),
    "tab-b",
  );

  assert.equal(reconciled["tab-a"].baseVersion, 0);
  assert.equal(reconciled["tab-a"].employeeId, "101");
  assert.equal(reconciled["tab-a"].conflict, true);
});

test("conflicted drafts cannot be saved until HR explicitly reconciles them", () => {
  let drafts = createGoogleDocsMappingDrafts(
    serverTabs({ firstEmployeeId: 1, firstVersion: 1 }),
  );
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-a", {
    status: "VERIFIED",
    employeeId: "101",
  });
  drafts = reconcileGoogleDocsMappingSaveSuccess(
    drafts,
    serverTabs({ firstEmployeeId: 2, firstVersion: 2 }),
    "tab-b",
  );

  assert.equal(canSaveGoogleDocsMappingDraft(drafts["tab-a"]), false);

  const keptChoice = confirmGoogleDocsMappingDraft(
    drafts,
    serverTabs({ firstEmployeeId: 2, firstVersion: 2 }),
    "tab-a",
  );
  assert.equal(keptChoice["tab-a"].employeeId, "101");
  assert.equal(keptChoice["tab-a"].baseVersion, 2);
  assert.equal(keptChoice["tab-a"].dirty, true);
  assert.equal(keptChoice["tab-a"].conflict, false);
  assert.equal(canSaveGoogleDocsMappingDraft(keptChoice["tab-a"]), true);
  assert.deepEqual(
    buildGoogleDocsMappingUpdateInput("tab-a", keptChoice["tab-a"]),
    {
      tab_id: "tab-a",
      status: "VERIFIED",
      employee_id: 101,
      expected_version: 2,
    },
  );
});

test("using server data resets only the selected tab and preserves another dirty tab", () => {
  let drafts = createGoogleDocsMappingDrafts(
    serverTabs({
      firstEmployeeId: 1,
      firstVersion: 1,
      secondEmployeeId: 20,
      secondVersion: 4,
    }),
  );
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-a", {
    status: "VERIFIED",
    employeeId: "101",
  });
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-b", {
    status: "VERIFIED",
    employeeId: "202",
  });

  const refreshedTabs = serverTabs({
    firstEmployeeId: 2,
    firstVersion: 2,
    secondEmployeeId: 20,
    secondVersion: 4,
  });
  const conflicted = reconcileGoogleDocsMappingConflict(
    drafts,
    refreshedTabs,
    "tab-a",
  );
  const serverChoice = resetGoogleDocsMappingDraftFromServer(
    conflicted,
    refreshedTabs,
    "tab-a",
  );

  assert.deepEqual(serverChoice["tab-a"], {
    status: "VERIFIED",
    employeeId: "2",
    baseStatus: "VERIFIED",
    baseEmployeeId: "2",
    baseVersion: 2,
    dirty: false,
    conflict: false,
    conflictExpectedVersion: null,
    conflictRefreshPending: false,
  });
  assert.deepEqual(serverChoice["tab-b"], {
    status: "VERIFIED",
    employeeId: "202",
    baseStatus: "VERIFIED",
    baseEmployeeId: "20",
    baseVersion: 4,
    dirty: true,
    conflict: false,
    conflictExpectedVersion: null,
    conflictRefreshPending: false,
  });
});

test("duplicate titles and reordered server tabs remain isolated by tab id", () => {
  let drafts = createGoogleDocsMappingDrafts(
    serverTabs({ firstVersion: 1, secondVersion: 3 }),
  );
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-a", {
    status: "VERIFIED",
    employeeId: "101",
  });

  const reordered = serverTabs({
    firstVersion: 1,
    secondEmployeeId: 202,
    secondVersion: 4,
  }).reverse();
  const reconciled = reconcileGoogleDocsMappingSaveSuccess(
    drafts,
    reordered,
    "tab-b",
  );

  assert.equal(reconciled["tab-a"].employeeId, "101");
  assert.equal(reconciled["tab-a"].baseVersion, 1);
  assert.equal(reconciled["tab-a"].dirty, true);
  assert.equal(reconciled["tab-b"].employeeId, "202");
  assert.equal(reconciled["tab-b"].baseVersion, 4);
  assert.equal(reconciled["tab-b"].dirty, false);
});

test("shared generations reject old GET, ignored abort and cross-plan responses", () => {
  const oldGet = { generation: 1, planId: 10 };
  const successfulPut = { generation: 2, planId: 10 };
  let activeGeneration = 2;
  let activePlanId = 10;
  let currentTabs = serverTabs({ firstEmployeeId: 2, firstVersion: 2 });

  assert.equal(
    shouldApplyGoogleDocsMappingResponse(
      activeGeneration,
      activePlanId,
      successfulPut,
      false,
    ),
    true,
  );
  assert.equal(
    shouldApplyGoogleDocsMappingResponse(
      activeGeneration,
      activePlanId,
      oldGet,
      false,
    ),
    false,
  );

  const ignoredAbortResponse = serverTabs({
    firstEmployeeId: 1,
    firstVersion: 1,
  });
  if (
    shouldApplyGoogleDocsMappingResponse(
      activeGeneration,
      activePlanId,
      oldGet,
      false,
    )
  ) {
    currentTabs = ignoredAbortResponse;
  }
  assert.equal(currentTabs[0].version, 2);
  assert.equal(
    isGoogleDocsMappingSnapshotRegressive(currentTabs, ignoredAbortResponse),
    true,
  );

  const otherPlan = { generation: 3, planId: 11 };
  activeGeneration = 3;
  activePlanId = 10;
  assert.equal(
    shouldApplyGoogleDocsMappingResponse(
      activeGeneration,
      activePlanId,
      otherPlan,
      false,
    ),
    false,
  );
  assert.equal(
    shouldApplyGoogleDocsMappingResponse(
      activeGeneration,
      activePlanId,
      { generation: 3, planId: 10 },
      true,
    ),
    false,
  );
});

test("network failure keeps retryable drafts and duplicate titles remain tab-specific", () => {
  let drafts = createGoogleDocsMappingDrafts(serverTabs());
  drafts = updateGoogleDocsMappingDraft(drafts, "tab-b", {
    status: "VERIFIED",
    employeeId: "202",
  });

  const afterNetworkFailure = drafts;
  assert.equal(afterNetworkFailure["tab-b"].dirty, true);
  assert.equal(afterNetworkFailure["tab-b"].employeeId, "202");
  assert.notDeepEqual(
    afterNetworkFailure["tab-a"],
    afterNetworkFailure["tab-b"],
  );

  const afterRetry = reconcileGoogleDocsMappingSaveSuccess(
    afterNetworkFailure,
    serverTabs({ secondEmployeeId: 202, secondVersion: 1 }),
    "tab-b",
  );
  assert.equal(afterRetry["tab-b"].dirty, false);
  assert.equal(afterRetry["tab-b"].employeeId, "202");
  assert.equal(afterRetry["tab-b"].baseVersion, 1);
  assert.equal(afterRetry["tab-a"].employeeId, "");
});

test("Google Docs employee mapping uses backend actions and scoped plan routes", async () => {
  const [detail, panel, service, types] = await Promise.all([
    readSource("src/pages/WeeklyPlans/WeeklyPlanDetail.tsx"),
    readSource(
      "src/components/weekly-plans/GoogleDocsEmployeeMappingPanel.tsx",
    ),
    readSource("src/services/weeklyPlanningService.ts"),
    readSource("src/types/weeklyPlanning.ts"),
  ]);

  assert.match(detail, /plan\.actions\.can_manage_google_docs_mappings/);
  assert.match(
    detail,
    /<GoogleDocsEmployeeMappingPanel[\s\S]*?planId=\{plan\.id\}/,
  );
  assert.doesNotMatch(detail + panel, /role\s*===|HR_MANAGER|OWNER|ADMIN/);
  assert.match(
    service,
    /`\/api\/weekly-plans\/\$\{id\}\/google-docs\/employee-mappings`/,
  );
  assert.match(service, /requestGoogleDocsEmployeeMappingsBatch/);
  assert.match(service, /method: "PUT"/);
  assert.doesNotMatch(service, /organization_id|document_id|document_url/);
  assert.match(types, /can_manage_google_docs_mappings: boolean/);
  assert.match(types, /"UNVERIFIED"[\s\S]*"NOT_APPLICABLE"/);
  assert.match(types, /"CONFIRMED_EXACT"\s*\|\s*"CONFIRMED_ALIAS"/);
});

test("mapping UI preserves tab identity, requires explicit confirmation, and handles conflicts safely", async () => {
  const panel = await readSource(
    "src/components/weekly-plans/GoogleDocsEmployeeMappingPanel.tsx",
  );

  assert.match(panel, /key=\{tab\.tab_id\}/);
  assert.match(panel, /buildGoogleDocsMappingBatchUpdateInput/);
  assert.match(panel, /draft\.status === "VERIFIED"[\s\S]*!draft\.employeeId/);
  assert.match(panel, /status: "VERIFIED"/);
  assert.match(panel, /useSuggestion/);
  assert.match(panel, /onClick=\{\(\) => void saveAll\(\)\}/);
  assert.doesNotMatch(panel, /void save\(tab\.tab_id\)/);
  assert.match(panel, /getGoogleDocsMappingConflictKind\(nextError\)/);
  assert.match(
    panel,
    /getWeeklyPlanGoogleDocsEmployeeMappings\(\s*planId,\s*controller\.signal/,
  );
  assert.match(panel, /new AbortController\(\)/);
  assert.match(
    panel,
    /return \(\) => \{[\s\S]*activeControllerRef\.current\?\.abort\(\)/,
  );
  assert.doesNotMatch(
    panel,
    /localStorage|sessionStorage|dangerouslySetInnerHTML/,
  );
  assert.doesNotMatch(panel, /createTask|createEntry|aiExtract|OpenAI/);
});

test("mapping UI coordinates loads, saves and conflict refreshes with one generation", async () => {
  const [panel, service, batchRequest, apiClient] = await Promise.all([
    readSource(
      "src/components/weekly-plans/GoogleDocsEmployeeMappingPanel.tsx",
    ),
    readSource("src/services/weeklyPlanningService.ts"),
    readSource("src/services/googleDocsEmployeeMappingRequest.ts"),
    readSource("src/services/apiClient.ts"),
  ]);

  assert.match(panel, /if \(operationInFlightRef\.current \|\|/);
  assert.match(panel, /activeControllerRef\.current\?\.abort\(\)/);
  assert.match(panel, /generation: \+\+requestGenerationRef\.current/);
  assert.match(
    panel,
    /updateWeeklyPlanGoogleDocsEmployeeMappingsBatch\([\s\S]*controller\.signal/,
  );
  assert.match(
    panel,
    /shouldApplyGoogleDocsMappingResponse\([\s\S]*requestGenerationRef\.current,[\s\S]*activePlanIdRef\.current,[\s\S]*token,[\s\S]*controller\.signal\.aborted/,
  );
  assert.match(
    panel,
    /getWeeklyPlanGoogleDocsEmployeeMappings\([\s\S]*controller\.signal/,
  );
  assert.ok(
    panel.match(/savingBatch/g)?.length >= 6,
    "all mapping controls must lock during a batch save",
  );
  assert.match(panel, /markGoogleDocsMappingBatchConflictPending/);
  assert.match(panel, /retryBatchConflictRefresh/);
  assert.match(
    panel,
    /const submitted = buildGoogleDocsMappingBatchUpdateInput/,
  );
  assert.match(panel, /getGoogleDocsMappingConflictKind/);
  assert.match(panel, /isGoogleDocsMappingBatchConflictRefreshTrusted/);
  assert.match(panel, /isGoogleDocsMappingSnapshotRegressive/);
  assert.match(panel, /setWriteBlocked\(true\)/);
  assert.match(
    service,
    /expected_version: number;[\s\S]*signal\?: AbortSignal/,
  );
  assert.match(
    batchRequest,
    /body: JSON\.stringify\(\{ mappings \}\),[\s\S]*signal/,
  );
  assert.match(apiClient, /readonly code\?: string/);
  assert.match(apiClient, /typeof payload\.code === "string"/);
});

test("mapping translations include every persisted and derived status", async () => {
  const translations = JSON.parse(
    await readSource("src/locales/vi/common.json"),
  );
  const mapping = translations.weeklyPlanning.googleDocsEmployeeMapping;

  assert.equal(mapping.statuses.UNVERIFIED, "Chưa xác minh");
  assert.equal(mapping.statuses.VERIFIED, "Đã xác minh");
  assert.equal(mapping.statuses.VERIFIED_PENDING, "Đã chọn, chờ lưu xác minh");
  assert.equal(mapping.statuses.NEEDS_REVIEW, "Cần kiểm tra");
  assert.equal(mapping.statuses.NOT_APPLICABLE, "Không áp dụng");
  assert.match(mapping.privacyNotice, /HR chủ động lưu lựa chọn/);
  assert.match(
    mapping.conflict.refreshPending,
    /chưa tải được dữ liệu mới nhất/i,
  );
  assert.match(mapping.conflict.retryRefresh, /Thử tải lại dữ liệu/);
  assert.equal(mapping.saveAll, "Lưu tất cả xác minh");
  assert.equal(mapping.completed, "Đã hoàn tất xác minh tab");
  assert.match(mapping.readyForAi, /Sẵn sàng phân tích công việc bằng AI/);
});

test("Google Docs multi-tab selection routes to scoped AI instead of the manual assignee modal", async () => {
  const [sourceDocument, translationsSource] = await Promise.all([
    readSource("src/components/weekly-plans/WeeklyPlanSourceDocument.tsx"),
    readSource("src/locales/vi/common.json"),
  ]);
  const translations = JSON.parse(translationsSource);

  assert.match(sourceDocument, /plan\.source_type === "GOOGLE_DOCS"/);
  assert.match(
    sourceDocument,
    /spansMultipleGoogleDocsTabs\(selectedPassages\)/,
  );
  assert.doesNotMatch(
    sourceDocument,
    /setLocalError\(t\("multiTabAssignmentWarning"\)\)/,
  );
  assert.match(sourceDocument, /googleDocsSelectionActions\.canAnalyzeWithAi/);
  assert.match(sourceDocument, /extractSelectedGroupsWithAi/);
  assert.match(sourceDocument, /sourcePassageIds/);
  assert.match(sourceDocument, /setDraft\(\{/);
  assert.match(
    translations.weeklyPlanning.sourceDocument.multiTabAssignmentWarning,
    /Mỗi tab có thể thuộc một nhân viên khác nhau/,
  );
});
