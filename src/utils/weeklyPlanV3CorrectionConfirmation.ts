import type { WeeklyPlanV3CorrectionEmployeeInput } from "@/types/weeklyPlanning";

export type WeeklyPlanV3CorrectionFailureMode =
  "AMBIGUOUS" | "STALE" | "VALIDATION";

export type WeeklyPlanV3PendingCorrectionConfirmation = {
  planId: number;
  requestId: string;
  employees: WeeklyPlanV3CorrectionEmployeeInput[];
  payloadFingerprint: string;
};

export type WeeklyPlanV3CorrectionConfirmationResolution = {
  confirmation: WeeklyPlanV3PendingCorrectionConfirmation;
  reconciliationRequired: boolean;
};

const canonicalEmployees = (
  employees: WeeklyPlanV3CorrectionEmployeeInput[],
): WeeklyPlanV3CorrectionEmployeeInput[] =>
  employees
    .map((employee) => ({ ...employee }))
    .sort((left, right) => left.assignee_id - right.assignee_id);

export const weeklyPlanV3CorrectionPayloadFingerprint = (
  employees: WeeklyPlanV3CorrectionEmployeeInput[],
): string => JSON.stringify(canonicalEmployees(employees));

export const resolveWeeklyPlanV3CorrectionConfirmation = (
  pending: WeeklyPlanV3PendingCorrectionConfirmation | null,
  planId: number,
  currentEmployees: WeeklyPlanV3CorrectionEmployeeInput[],
  createRequestId: () => string,
): WeeklyPlanV3CorrectionConfirmationResolution => {
  const currentFingerprint =
    weeklyPlanV3CorrectionPayloadFingerprint(currentEmployees);
  if (pending?.planId === planId) {
    return {
      confirmation: pending,
      reconciliationRequired: pending.payloadFingerprint !== currentFingerprint,
    };
  }

  return {
    confirmation: {
      planId,
      requestId: createRequestId(),
      employees: canonicalEmployees(currentEmployees),
      payloadFingerprint: currentFingerprint,
    },
    reconciliationRequired: false,
  };
};

export const clearConfirmedWeeklyPlanV3CorrectionSelection = (
  selected: number[],
  results: Array<{ assignee_id: number }>,
): number[] => {
  const confirmed = new Set(results.map((result) => result.assignee_id));
  return selected.filter((assigneeId) => !confirmed.has(assigneeId));
};

export const weeklyPlanV3CorrectionFailureMode = (
  error: unknown,
): WeeklyPlanV3CorrectionFailureMode => {
  const candidateStatus =
    typeof error === "object" &&
    error !== null &&
    "status" in error &&
    typeof error.status === "number"
      ? error.status
      : null;
  const status =
    candidateStatus !== null && Number.isInteger(candidateStatus)
      ? candidateStatus
      : null;

  if (status === 409) {
    return "STALE";
  }

  if (status === 0 || (status !== null && status >= 500)) {
    return "AMBIGUOUS";
  }

  if (status !== null && status >= 400 && status < 500) {
    return "VALIDATION";
  }

  return "AMBIGUOUS";
};
