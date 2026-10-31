import assert from "node:assert/strict";
import { test } from "node:test";
import {
  installWeeklyPlanV3NavigationSafety,
  guardWeeklyPlanV3HistoryListener,
} from "../src/utils/weeklyPlanV3NavigationSafety.ts";
import {
  resolveWeeklyPlanV3BatchConfirmation,
  weeklyPlanV3BatchPayloadFingerprint,
} from "../src/utils/weeklyPlanV3BatchConfirmation.ts";
import {
  persistWeeklyPlanV3ReviewerPending,
  restoreWeeklyPlanV3ReviewerPending,
} from "../src/utils/weeklyPlanV3ReviewWorkspace.ts";

const memoryStorage = () => {
  const values = new Map();
  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
    values,
  };
};

const navigationHost = ({ confirmLeave, index = 5 }) => {
  const listeners = new Map();
  const historyCalls = [];
  let confirmCount = 0;
  const host = {
    location: { href: "https://kps.test/weekly-plans/900/review" },
    history: {
      state: { idx: index, key: "review" },
      go: (delta) => historyCalls.push({ type: "go", delta }),
      pushState: (state, _unused, url) =>
        historyCalls.push({ type: "pushState", state, url }),
    },
    confirm: () => {
      confirmCount += 1;
      return confirmLeave;
    },
    addEventListener: (type, listener) => listeners.set(type, listener),
    removeEventListener: (type, listener) => {
      if (listeners.get(type) === listener) listeners.delete(type);
    },
  };

  return {
    host,
    historyCalls,
    listeners,
    confirmCount: () => confirmCount,
  };
};

const navigationEvent = (changes = {}) => {
  const calls = [];
  return {
    button: 0,
    preventDefault: () => calls.push("preventDefault"),
    stopPropagation: () => calls.push("stopPropagation"),
    stopImmediatePropagation: () => calls.push("stopImmediatePropagation"),
    calls,
    ...changes,
  };
};

const ownerPending = () => {
  const decisions = [
    {
      assignee_id: 22,
      decision: "ACCEPTED",
      expected_state_version: 2,
      expected_content_version: 3,
    },
  ];
  return {
    planId: 900,
    stage: "OWNER",
    requestId: "owner-request-immutable-1",
    decisions,
    payloadFingerprint: weeklyPlanV3BatchPayloadFingerprint(decisions),
    taskCountPreview: 4,
  };
};

const assistantPending = () => {
  const decisions = [
    {
      assignee_id: 11,
      decision: "CHANGES_REQUESTED",
      expected_state_version: 7,
      expected_content_version: 8,
    },
  ];
  return {
    planId: 900,
    stage: "ASSISTANT",
    requestId: "assistant-request-immutable-1",
    decisions,
    payloadFingerprint: weeklyPlanV3BatchPayloadFingerprint(decisions),
  };
};

test("OWNER unresolved request can cancel internal Link navigation and remains recoverable", () => {
  const storage = memoryStorage();
  const pending = ownerPending();
  persistWeeklyPlanV3ReviewerPending(
    900,
    { assistantPending: null, ownerPending: pending },
    storage,
  );
  const navigation = navigationHost({ confirmLeave: false });
  const cleanup = installWeeklyPlanV3NavigationSafety(
    navigation.host,
    "Unresolved request",
  );
  const event = navigationEvent({
    target: {
      closest: () => ({
        href: "https://kps.test/weekly-plans",
        target: "",
        hasAttribute: () => false,
      }),
    },
  });

  navigation.listeners.get("click")(event);

  assert.equal(navigation.confirmCount(), 1);
  assert.deepEqual(event.calls, [
    "preventDefault",
    "stopPropagation",
    "stopImmediatePropagation",
  ]);
  assert.deepEqual(
    restoreWeeklyPlanV3ReviewerPending(900, storage).ownerPending,
    pending,
  );
  cleanup();
  assert.equal(navigation.listeners.size, 0);
});

test("Assistant unresolved request can cancel browser Back and preserve exact retry", () => {
  const storage = memoryStorage();
  const pending = assistantPending();
  persistWeeklyPlanV3ReviewerPending(
    900,
    { assistantPending: pending, ownerPending: null },
    storage,
  );
  const navigation = navigationHost({ confirmLeave: false, index: 5 });
  installWeeklyPlanV3NavigationSafety(navigation.host, "Unresolved request");
  const event = navigationEvent({ state: { idx: 4, key: "list" } });

  let acceptedPops = 0;
  const routerListener = guardWeeklyPlanV3HistoryListener(
    navigation.host,
    () => acceptedPops++,
  );
  routerListener(event);
  routerListener(navigationEvent({ state: { idx: 5 } }));

  assert.equal(navigation.confirmCount(), 1);
  assert.deepEqual(navigation.historyCalls, [{ type: "go", delta: 1 }]);
  assert.equal(acceptedPops, 0);

  const restored = restoreWeeklyPlanV3ReviewerPending(
    900,
    storage,
  ).assistantPending;
  let generatedKeys = 0;
  const retry = resolveWeeklyPlanV3BatchConfirmation(
    restored,
    900,
    "ASSISTANT",
    [{ ...pending.decisions[0], decision: "ACCEPTED" }],
    () => `new-key-${++generatedKeys}`,
  );
  assert.equal(retry.confirmation.requestId, pending.requestId);
  assert.deepEqual(retry.confirmation.decisions, pending.decisions);
  assert.equal(
    retry.confirmation.payloadFingerprint,
    pending.payloadFingerprint,
  );
  assert.equal(retry.reconciliationRequired, true);
  assert.equal(generatedKeys, 0);
});

test("allowed navigation keeps minimal pending metadata without plan content", () => {
  const storage = memoryStorage();
  const owner = ownerPending();
  const assistant = assistantPending();
  persistWeeklyPlanV3ReviewerPending(
    900,
    { assistantPending: assistant, ownerPending: owner },
    storage,
  );
  const navigation = navigationHost({ confirmLeave: true });
  installWeeklyPlanV3NavigationSafety(navigation.host, "Unresolved request");
  const event = navigationEvent({
    target: {
      closest: () => ({
        href: "https://kps.test/weekly-plans",
        target: "",
        hasAttribute: () => false,
      }),
    },
  });

  navigation.listeners.get("click")(event);

  assert.deepEqual(event.calls, []);
  const serialized = [...storage.values.values()].join("");
  assert.match(serialized, /owner-request-immutable-1/);
  assert.match(serialized, /assistant-request-immutable-1/);
  assert.doesNotMatch(
    serialized,
    /employee_name|entries|comments|Work content/,
  );
});

test("navigation safety is not installed without an unresolved request", () => {
  const navigation = navigationHost({ confirmLeave: false });
  installWeeklyPlanV3NavigationSafety(
    navigation.host,
    "Unresolved request",
    false,
  );

  assert.equal(navigation.listeners.size, 0);
  assert.equal(navigation.confirmCount(), 0);
});

test("successful reconciliation clears only the confirmed stage recovery", () => {
  const storage = memoryStorage();
  const owner = ownerPending();
  const assistant = assistantPending();
  persistWeeklyPlanV3ReviewerPending(
    900,
    { assistantPending: assistant, ownerPending: owner },
    storage,
  );
  persistWeeklyPlanV3ReviewerPending(
    900,
    { assistantPending: assistant, ownerPending: null },
    storage,
  );

  const restored = restoreWeeklyPlanV3ReviewerPending(900, storage);
  assert.equal(restored.ownerPending, null);
  assert.deepEqual(restored.assistantPending, assistant);
});

test("router callback is gated for rejected Forward and restored after guard cleanup", () => {
  const navigation = navigationHost({ confirmLeave: false, index: 5 });
  const cleanup = installWeeklyPlanV3NavigationSafety(
    navigation.host,
    "Unresolved",
  );
  const accepted = [];
  const routerListener = guardWeeklyPlanV3HistoryListener(
    navigation.host,
    (event) => accepted.push(event.state.idx),
  );
  routerListener(navigationEvent({ state: { idx: 6 } }));
  routerListener(navigationEvent({ state: { idx: 5 } }));
  assert.deepEqual(navigation.historyCalls, [{ type: "go", delta: -1 }]);
  assert.deepEqual(accepted, []);
  cleanup();
  routerListener(navigationEvent({ state: { idx: 6 } }));
  assert.deepEqual(accepted, [6]);
});

test("accepted history traversal is delegated once without synthetic history entries", () => {
  const navigation = navigationHost({ confirmLeave: true });
  const cleanup = installWeeklyPlanV3NavigationSafety(
    navigation.host,
    "Unresolved",
  );
  let accepted = 0;
  guardWeeklyPlanV3HistoryListener(
    navigation.host,
    () => accepted++,
  )(navigationEvent({ state: { idx: 4 } }));
  assert.equal(accepted, 1);
  assert.equal(navigation.confirmCount(), 1);
  assert.deepEqual(navigation.historyCalls, []);
  cleanup();
});

test("external document links rely on native unload protection without a second click prompt", () => {
  const navigation = navigationHost({ confirmLeave: false });
  const cleanup = installWeeklyPlanV3NavigationSafety(
    navigation.host,
    "Unresolved",
  );
  const event = navigationEvent({
    target: {
      closest: () => ({
        href: "https://external.test/",
        target: "",
        hasAttribute: () => false,
      }),
    },
  });
  navigation.listeners.get("click")(event);
  assert.equal(navigation.confirmCount(), 0);
  assert.deepEqual(event.calls, []);
  cleanup();
});
