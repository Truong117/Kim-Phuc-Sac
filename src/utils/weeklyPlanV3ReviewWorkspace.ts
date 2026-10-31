import type {
  WeeklyPlan,
  WeeklyPlanEmployeeWorkflow,
  WeeklyPlanEntry,
  WeeklyPlanPublicationSummary,
  WeeklyPlanV3AssistantBatchDecisionInput,
  WeeklyPlanV3OwnerBatchDecisionInput,
} from "@/types/weeklyPlanning";
import {
  weeklyPlanV3BatchPayloadFingerprint,
  type WeeklyPlanV3PendingBatchConfirmation,
  type WeeklyPlanV3ReviewerStage,
  type WeeklyPlanV3VersionedDecision,
} from "./weeklyPlanV3BatchConfirmation.ts";

export type WeeklyPlanV3ReviewerView = "OVERVIEW" | "ASSISTANT" | "OWNER";

export type WeeklyPlanV3OwnerPendingConfirmation =
  WeeklyPlanV3PendingBatchConfirmation<WeeklyPlanV3OwnerBatchDecisionInput> & {
    taskCountPreview: number;
  };

export type WeeklyPlanV3ReviewerWorkspaceState = {
  view: WeeklyPlanV3ReviewerView;
  assistantPending: WeeklyPlanV3PendingBatchConfirmation<WeeklyPlanV3AssistantBatchDecisionInput> | null;
  ownerPending: WeeklyPlanV3OwnerPendingConfirmation | null;
};

export type WeeklyPlanV3ReviewerWorkspaceAction =
  | { type: "SET_VIEW"; view: WeeklyPlanV3ReviewerView }
  | {
      type: "SET_ASSISTANT_PENDING";
      pending: WeeklyPlanV3ReviewerWorkspaceState["assistantPending"];
    }
  | {
      type: "SET_OWNER_PENDING";
      pending: WeeklyPlanV3ReviewerWorkspaceState["ownerPending"];
    };

export const createWeeklyPlanV3ReviewerWorkspaceState = (
  view: WeeklyPlanV3ReviewerView,
): WeeklyPlanV3ReviewerWorkspaceState => ({
  view,
  assistantPending: null,
  ownerPending: null,
});

type WeeklyPlanV3RecoveryStorage = Pick<
  Storage,
  "getItem" | "setItem" | "removeItem"
>;

const recoveryStorageKey = (planId: number): string =>
  `kps:weekly-plan-v3-reviewer-recovery:${planId}`;

const validDecision = (
  value: unknown,
  stage: WeeklyPlanV3ReviewerStage,
): value is WeeklyPlanV3VersionedDecision => {
  if (typeof value !== "object" || value === null) return false;
  const decision = value as Partial<WeeklyPlanV3VersionedDecision>;
  const allowed =
    stage === "ASSISTANT"
      ? ["ACCEPTED", "CHANGES_REQUESTED"]
      : ["ACCEPTED", "CHANGES_REQUESTED", "APPROVE_NO_WORK"];

  return (
    Number.isInteger(decision.assignee_id) &&
    Number(decision.assignee_id) > 0 &&
    typeof decision.decision === "string" &&
    allowed.includes(decision.decision) &&
    Number.isInteger(decision.expected_state_version) &&
    Number(decision.expected_state_version) >= 0 &&
    Number.isInteger(decision.expected_content_version) &&
    Number(decision.expected_content_version) >= 0
  );
};

const validPending = <TDecision extends WeeklyPlanV3VersionedDecision>(
  value: unknown,
  planId: number,
  stage: WeeklyPlanV3ReviewerStage,
): value is WeeklyPlanV3PendingBatchConfirmation<TDecision> => {
  if (typeof value !== "object" || value === null) return false;
  const pending = value as Partial<
    WeeklyPlanV3PendingBatchConfirmation<WeeklyPlanV3VersionedDecision>
  >;
  if (
    pending.planId !== planId ||
    pending.stage !== stage ||
    typeof pending.requestId !== "string" ||
    pending.requestId.length === 0 ||
    pending.requestId.length > 128 ||
    typeof pending.payloadFingerprint !== "string" ||
    !Array.isArray(pending.decisions) ||
    pending.decisions.length === 0 ||
    pending.decisions.length > 100 ||
    !pending.decisions.every((decision) => validDecision(decision, stage))
  ) {
    return false;
  }

  return (
    weeklyPlanV3BatchPayloadFingerprint(pending.decisions) ===
    pending.payloadFingerprint
  );
};

export const restoreWeeklyPlanV3ReviewerPending = (
  planId: number,
  storage: WeeklyPlanV3RecoveryStorage | null,
): Pick<
  WeeklyPlanV3ReviewerWorkspaceState,
  "assistantPending" | "ownerPending"
> => {
  const empty = { assistantPending: null, ownerPending: null };
  if (!storage) return empty;

  try {
    const raw = storage.getItem(recoveryStorageKey(planId));
    if (!raw) return empty;
    const value = JSON.parse(raw) as {
      version?: unknown;
      assistantPending?: unknown;
      ownerPending?: unknown;
    };
    if (value.version !== 1) return empty;

    const assistantPending =
      validPending<WeeklyPlanV3AssistantBatchDecisionInput>(
        value.assistantPending,
        planId,
        "ASSISTANT",
      )
        ? value.assistantPending
        : null;
    const ownerTaskCount =
      typeof value.ownerPending === "object" &&
      value.ownerPending !== null &&
      "taskCountPreview" in value.ownerPending
        ? value.ownerPending.taskCountPreview
        : null;
    const ownerPending =
      validPending<WeeklyPlanV3OwnerBatchDecisionInput>(
        value.ownerPending,
        planId,
        "OWNER",
      ) &&
      typeof ownerTaskCount === "number" &&
      Number.isInteger(ownerTaskCount) &&
      ownerTaskCount >= 0
        ? { ...value.ownerPending, taskCountPreview: ownerTaskCount }
        : null;

    return { assistantPending, ownerPending };
  } catch {
    return empty;
  }
};

export const persistWeeklyPlanV3ReviewerPending = (
  planId: number,
  state: Pick<
    WeeklyPlanV3ReviewerWorkspaceState,
    "assistantPending" | "ownerPending"
  >,
  storage: WeeklyPlanV3RecoveryStorage | null,
): void => {
  if (!storage) return;
  const key = recoveryStorageKey(planId);
  try {
    if (!state.assistantPending && !state.ownerPending) {
      storage.removeItem(key);
      return;
    }
    storage.setItem(
      key,
      JSON.stringify({
        version: 1,
        assistantPending: state.assistantPending,
        ownerPending: state.ownerPending,
      }),
    );
  } catch {
    // Recovery storage is best-effort; in-memory blocking remains authoritative.
  }
};

export const weeklyPlanV3ReviewerWorkspaceReducer = (
  state: WeeklyPlanV3ReviewerWorkspaceState,
  action: WeeklyPlanV3ReviewerWorkspaceAction,
): WeeklyPlanV3ReviewerWorkspaceState => {
  switch (action.type) {
    case "SET_VIEW":
      return { ...state, view: action.view };
    case "SET_ASSISTANT_PENDING":
      return { ...state, assistantPending: action.pending };
    case "SET_OWNER_PENDING":
      return { ...state, ownerPending: action.pending };
  }
};

export type WeeklyPlanV3EmployeeFilter =
  | "MY_REVIEW"
  | "OTHER_REVIEW"
  | "HR_CORRECTION"
  | "PUBLISHED"
  | "NO_WORK_APPROVED"
  | "ALL";

export const weeklyPlanV3EntriesByAssignee = (
  entries: WeeklyPlanEntry[],
): Map<number, WeeklyPlanEntry[]> => {
  const groups = new Map<number, WeeklyPlanEntry[]>();
  entries.forEach((entry) => {
    if (!entry.assignee) return;
    const current = groups.get(entry.assignee.id) ?? [];
    current.push(entry);
    groups.set(entry.assignee.id, current);
  });

  return groups;
};

export const weeklyPlanV3WorkflowNeedsMyReview = (
  workflow: WeeklyPlanEmployeeWorkflow,
): boolean =>
  (workflow.viewer_context.mode === "ASSISTANT_REVIEW" &&
    (workflow.actions.can_accept || workflow.actions.can_request_changes)) ||
  (workflow.viewer_context.mode === "OWNER_REVIEW" &&
    (workflow.actions.can_publish ||
      workflow.actions.can_request_changes ||
      workflow.actions.can_approve_no_work));

export const filterWeeklyPlanV3Workflows = (
  workflows: WeeklyPlanEmployeeWorkflow[],
  filter: WeeklyPlanV3EmployeeFilter,
): WeeklyPlanEmployeeWorkflow[] =>
  workflows.filter((workflow) => {
    switch (filter) {
      case "MY_REVIEW":
        return weeklyPlanV3WorkflowNeedsMyReview(workflow);
      case "OTHER_REVIEW":
        return (
          ["AWAITING_ASSISTANT", "STAGED_FOR_OWNER"].includes(workflow.state) &&
          !weeklyPlanV3WorkflowNeedsMyReview(workflow)
        );
      case "HR_CORRECTION":
        return workflow.state === "HR_CORRECTION";
      case "PUBLISHED":
        return workflow.state === "PUBLISHED";
      case "NO_WORK_APPROVED":
        return workflow.state === "NO_WORK_APPROVED";
      case "ALL":
        return true;
    }
  });

export const weeklyPlanV3TerminalProgress = (
  summary: WeeklyPlanPublicationSummary | undefined,
): { terminal: number; total: number; percentage: number } => {
  const total = summary?.total ?? 0;
  const terminal = (summary?.published ?? 0) + (summary?.no_work_approved ?? 0);

  return {
    terminal,
    total,
    percentage: total === 0 ? 0 : Math.round((terminal / total) * 100),
  };
};

export const weeklyPlanV3HasHrCorrectionAccess = (
  plan: Pick<WeeklyPlan, "workflow_version" | "employee_workflows">,
): boolean =>
  plan.workflow_version === 3 &&
  (plan.employee_workflows ?? []).some(
    (workflow) =>
      workflow.state === "HR_CORRECTION" &&
      (workflow.actions.can_correct || workflow.actions.can_resubmit),
  );
