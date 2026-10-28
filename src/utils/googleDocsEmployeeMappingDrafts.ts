export type GoogleDocsMappingDraftStatus =
  "VERIFIED" | "NEEDS_REVIEW" | "NOT_APPLICABLE";

export type GoogleDocsMappingSuggestionBasis =
  "CONFIRMED_EXACT" | "CONFIRMED_ALIAS";

export type GoogleDocsMappingServerTab = {
  tab_id: string;
  status: "UNVERIFIED" | "VERIFIED" | "NEEDS_REVIEW" | "NOT_APPLICABLE";
  employee: { id: number } | null;
  suggestion?: {
    employee: { id: number };
    basis: GoogleDocsMappingSuggestionBasis;
  } | null;
  version: number;
  reason?: "EMPLOYEE_INELIGIBLE" | null;
};

export type GoogleDocsMappingDraft = {
  status: GoogleDocsMappingDraftStatus;
  employeeId: string;
  baseStatus: GoogleDocsMappingDraftStatus;
  baseEmployeeId: string;
  baseVersion: number;
  dirty: boolean;
  conflict: boolean;
  conflictExpectedVersion: number | null;
  conflictRefreshPending: boolean;
  prefillBasis?: GoogleDocsMappingSuggestionBasis;
};

export type GoogleDocsMappingDrafts = Record<string, GoogleDocsMappingDraft>;

const fromServer = (
  tab: GoogleDocsMappingServerTab,
  prefillSuggestions = true,
): GoogleDocsMappingDraft => {
  const baseStatus = tab.status === "UNVERIFIED" ? "NEEDS_REVIEW" : tab.status;
  const baseEmployeeId = tab.employee ? String(tab.employee.id) : "";
  const hasPersistedMapping =
    tab.version > 0 ||
    tab.status === "VERIFIED" ||
    tab.status === "NOT_APPLICABLE" ||
    tab.employee !== null;
  const shouldPrefill = Boolean(
    prefillSuggestions &&
    !hasPersistedMapping &&
    tab.reason !== "EMPLOYEE_INELIGIBLE" &&
    ["UNVERIFIED", "NEEDS_REVIEW"].includes(tab.status) &&
    tab.suggestion,
  );

  return {
    status: shouldPrefill ? "VERIFIED" : baseStatus,
    employeeId: shouldPrefill
      ? String(tab.suggestion?.employee.id)
      : baseEmployeeId,
    baseStatus,
    baseEmployeeId,
    baseVersion: tab.version,
    dirty: shouldPrefill,
    conflict: false,
    conflictExpectedVersion: null,
    conflictRefreshPending: false,
    ...(shouldPrefill ? { prefillBasis: tab.suggestion?.basis } : {}),
  };
};

const reconcileDraftWithServer = (
  current: GoogleDocsMappingDraft | undefined,
  tab: GoogleDocsMappingServerTab,
): GoogleDocsMappingDraft => {
  if (!current || (!current.dirty && !current.conflict)) {
    return fromServer(tab);
  }

  return {
    ...current,
    conflict: current.conflict || tab.version !== current.baseVersion,
  };
};

export type GoogleDocsMappingRequestToken = {
  generation: number;
  planId: number;
};

export const GOOGLE_DOCS_MAPPING_CONFLICT_CODE = "GOOGLE_DOCS_MAPPING_CONFLICT";
export const GOOGLE_DOCS_MAPPING_LOCKED_CODE = "GOOGLE_DOCS_MAPPING_LOCKED";

export type GoogleDocsMappingConflictKind = "VERSION" | "LOCKED" | "OTHER";

export const getGoogleDocsMappingConflictKind = (
  error: unknown,
): GoogleDocsMappingConflictKind | null => {
  if (typeof error !== "object" || error === null) return null;

  const candidate = error as { code?: unknown; status?: unknown };
  if (candidate.status !== 409) return null;
  if (candidate.code === GOOGLE_DOCS_MAPPING_CONFLICT_CODE) return "VERSION";
  if (candidate.code === GOOGLE_DOCS_MAPPING_LOCKED_CODE) return "LOCKED";

  return "OTHER";
};

export const shouldBlockGoogleDocsMappingWrites = (
  conflictKind: GoogleDocsMappingConflictKind | null,
): boolean => conflictKind === "LOCKED" || conflictKind === "OTHER";

export const isGoogleDocsMappingConflictRefreshTrusted = (
  tabs: readonly { tab_id: string; version?: unknown }[],
  tabId: string,
  submittedExpectedVersion: number,
): boolean => {
  if (
    !Number.isSafeInteger(submittedExpectedVersion) ||
    submittedExpectedVersion < 0
  ) {
    return false;
  }

  const tab = tabs.find((candidate) => candidate.tab_id === tabId);

  return Boolean(
    tab &&
    Number.isSafeInteger(tab.version) &&
    (tab.version as number) >= 0 &&
    (tab.version as number) > submittedExpectedVersion,
  );
};

export const createGoogleDocsMappingDrafts = (
  tabs: GoogleDocsMappingServerTab[],
): GoogleDocsMappingDrafts =>
  Object.fromEntries(tabs.map((tab) => [tab.tab_id, fromServer(tab)]));

export const reconcileGoogleDocsMappingSnapshot = (
  drafts: GoogleDocsMappingDrafts,
  tabs: GoogleDocsMappingServerTab[],
): GoogleDocsMappingDrafts =>
  Object.fromEntries(
    tabs.map((tab) => [
      tab.tab_id,
      reconcileDraftWithServer(drafts[tab.tab_id], tab),
    ]),
  );

export const updateGoogleDocsMappingDraft = (
  drafts: GoogleDocsMappingDrafts,
  tabId: string,
  values: Partial<Pick<GoogleDocsMappingDraft, "status" | "employeeId">>,
): GoogleDocsMappingDrafts => {
  const current = drafts[tabId];
  if (!current) return drafts;

  const status = values.status ?? current.status;
  const employeeId = values.employeeId ?? current.employeeId;
  const currentWithoutPrefill = { ...current };
  delete currentWithoutPrefill.prefillBasis;

  return {
    ...drafts,
    [tabId]: {
      ...currentWithoutPrefill,
      status,
      employeeId,
      dirty:
        status !== current.baseStatus || employeeId !== current.baseEmployeeId,
    },
  };
};

export const reconcileGoogleDocsMappingSaveSuccess = (
  drafts: GoogleDocsMappingDrafts,
  tabs: GoogleDocsMappingServerTab[],
  savedTabId: string,
): GoogleDocsMappingDrafts =>
  Object.fromEntries(
    tabs.map((tab) => {
      const current = drafts[tab.tab_id];
      if (tab.tab_id === savedTabId || !current) {
        return [tab.tab_id, fromServer(tab)];
      }

      return [tab.tab_id, reconcileDraftWithServer(current, tab)];
    }),
  );

export type GoogleDocsMappingBatchUpdateInput = {
  tab_id: string;
  status: GoogleDocsMappingDraftStatus;
  employee_id?: number;
  expected_version: number;
};

export const buildGoogleDocsMappingBatchUpdateInput = (
  tabs: readonly { tab_id: string }[],
  drafts: GoogleDocsMappingDrafts,
): GoogleDocsMappingBatchUpdateInput[] =>
  tabs.flatMap((tab) => {
    const draft = drafts[tab.tab_id];
    if (!draft?.dirty) return [];

    return [
      {
        tab_id: tab.tab_id,
        status: draft.status,
        ...(draft.status === "VERIFIED"
          ? { employee_id: Number(draft.employeeId) }
          : {}),
        expected_version: draft.baseVersion,
      },
    ];
  });

export const canSaveGoogleDocsMappingBatch = (
  tabs: readonly { tab_id: string }[],
  drafts: GoogleDocsMappingDrafts,
): boolean => {
  const dirtyDrafts = tabs
    .map((tab) => drafts[tab.tab_id])
    .filter((draft): draft is GoogleDocsMappingDraft => Boolean(draft?.dirty));

  return (
    dirtyDrafts.length > 0 &&
    dirtyDrafts.every((draft) => canSaveGoogleDocsMappingDraft(draft))
  );
};

export const reconcileGoogleDocsMappingBatchSaveSuccess = (
  tabs: GoogleDocsMappingServerTab[],
): GoogleDocsMappingDrafts =>
  Object.fromEntries(tabs.map((tab) => [tab.tab_id, fromServer(tab, false)]));

export const markGoogleDocsMappingBatchConflictPending = (
  drafts: GoogleDocsMappingDrafts,
  inputs: readonly GoogleDocsMappingBatchUpdateInput[],
): GoogleDocsMappingDrafts =>
  inputs.reduce(
    (next, input) =>
      markGoogleDocsMappingConflictPending(
        next,
        input.tab_id,
        input.expected_version,
      ),
    drafts,
  );

export const isGoogleDocsMappingBatchConflictRefreshTrusted = (
  tabs: readonly { tab_id: string; version?: unknown }[],
  inputs: readonly GoogleDocsMappingBatchUpdateInput[],
): boolean => {
  let hasNewerVersion = false;

  for (const input of inputs) {
    const tab = tabs.find((candidate) => candidate.tab_id === input.tab_id);
    if (!tab || !Number.isSafeInteger(tab.version)) return false;
    const version = tab.version as number;
    if (version < input.expected_version) return false;
    if (version > input.expected_version) hasNewerVersion = true;
  }

  return hasNewerVersion;
};

export const reconcileGoogleDocsMappingBatchConflict = (
  drafts: GoogleDocsMappingDrafts,
  tabs: GoogleDocsMappingServerTab[],
  inputs: readonly GoogleDocsMappingBatchUpdateInput[],
): GoogleDocsMappingDrafts => {
  const expectedVersions = new Map(
    inputs.map((input) => [input.tab_id, input.expected_version]),
  );

  return Object.fromEntries(
    tabs.map((tab) => {
      const current = drafts[tab.tab_id];
      if (!current) return [tab.tab_id, fromServer(tab)];
      const submittedVersion = expectedVersions.get(tab.tab_id);
      if (submittedVersion === undefined) {
        return [tab.tab_id, reconcileDraftWithServer(current, tab)];
      }

      return [
        tab.tab_id,
        {
          ...current,
          dirty: true,
          conflict: tab.version !== submittedVersion,
          conflictExpectedVersion: null,
          conflictRefreshPending: false,
        },
      ];
    }),
  );
};

export const reconcileGoogleDocsMappingConflict = (
  drafts: GoogleDocsMappingDrafts,
  tabs: GoogleDocsMappingServerTab[],
  conflictedTabId: string,
): GoogleDocsMappingDrafts =>
  Object.fromEntries(
    tabs.map((tab) => {
      const current = drafts[tab.tab_id];
      if (tab.tab_id === conflictedTabId && current) {
        return [
          tab.tab_id,
          {
            ...current,
            dirty: true,
            conflict: true,
            conflictExpectedVersion: null,
            conflictRefreshPending: false,
          },
        ];
      }

      return [tab.tab_id, reconcileDraftWithServer(current, tab)];
    }),
  );

export const markGoogleDocsMappingConflictPending = (
  drafts: GoogleDocsMappingDrafts,
  tabId: string,
  expectedVersion: number,
): GoogleDocsMappingDrafts => {
  const current = drafts[tabId];
  if (!current) return drafts;

  return {
    ...drafts,
    [tabId]: {
      ...current,
      dirty: true,
      conflict: true,
      conflictExpectedVersion: expectedVersion,
      conflictRefreshPending: true,
    },
  };
};

export const resetGoogleDocsMappingDraftFromServer = (
  drafts: GoogleDocsMappingDrafts,
  tabs: GoogleDocsMappingServerTab[],
  tabId: string,
): GoogleDocsMappingDrafts => {
  const serverTab = tabs.find((tab) => tab.tab_id === tabId);
  const current = drafts[tabId];
  if (!serverTab || current?.conflictRefreshPending) return drafts;

  return { ...drafts, [tabId]: fromServer(serverTab, false) };
};

export const confirmGoogleDocsMappingDraft = (
  drafts: GoogleDocsMappingDrafts,
  tabs: GoogleDocsMappingServerTab[],
  tabId: string,
): GoogleDocsMappingDrafts => {
  const current = drafts[tabId];
  const serverTab = tabs.find((tab) => tab.tab_id === tabId);
  if (!current || !serverTab || current.conflictRefreshPending) return drafts;
  const baseStatus =
    serverTab.status === "UNVERIFIED" ? "NEEDS_REVIEW" : serverTab.status;
  const baseEmployeeId = serverTab.employee
    ? String(serverTab.employee.id)
    : "";

  return {
    ...drafts,
    [tabId]: {
      ...current,
      baseStatus,
      baseEmployeeId,
      baseVersion: serverTab.version,
      dirty:
        current.status !== baseStatus || current.employeeId !== baseEmployeeId,
      conflict: false,
      conflictExpectedVersion: null,
      conflictRefreshPending: false,
    },
  };
};

export const canSaveGoogleDocsMappingDraft = (
  draft: GoogleDocsMappingDraft | undefined,
): boolean =>
  Boolean(
    draft &&
    !draft.conflict &&
    (draft.status !== "VERIFIED" || draft.employeeId),
  );

export const buildGoogleDocsMappingUpdateInput = (
  tabId: string,
  draft: GoogleDocsMappingDraft,
) => ({
  tab_id: tabId,
  status: draft.status,
  employee_id: draft.status === "VERIFIED" ? Number(draft.employeeId) : null,
  expected_version: draft.baseVersion,
});

export const isGoogleDocsMappingSnapshotRegressive = (
  currentTabs: GoogleDocsMappingServerTab[],
  incomingTabs: GoogleDocsMappingServerTab[],
): boolean => {
  const currentVersions = new Map(
    currentTabs.map((tab) => [tab.tab_id, tab.version]),
  );

  return incomingTabs.some((tab) => {
    const currentVersion = currentVersions.get(tab.tab_id);
    return currentVersion !== undefined && tab.version < currentVersion;
  });
};

export const shouldApplyGoogleDocsMappingResponse = (
  activeGeneration: number,
  activePlanId: number,
  responseToken: GoogleDocsMappingRequestToken,
  aborted: boolean,
): boolean =>
  !aborted &&
  responseToken.generation === activeGeneration &&
  responseToken.planId === activePlanId;
