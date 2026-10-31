export type WeeklyPlanV3BatchFailureMode = "AMBIGUOUS" | "STALE" | "VALIDATION";

export type WeeklyPlanV3VersionedDecision = {
  assignee_id: number;
  decision: string;
  expected_state_version: number;
  expected_content_version: number;
};

export type WeeklyPlanV3PendingBatchConfirmation<
  TDecision extends WeeklyPlanV3VersionedDecision,
> = {
  planId: number;
  requestId: string;
  decisions: TDecision[];
  payloadFingerprint: string;
};

export type WeeklyPlanV3BatchConfirmationResolution<
  TDecision extends WeeklyPlanV3VersionedDecision,
> = {
  confirmation: WeeklyPlanV3PendingBatchConfirmation<TDecision>;
  reconciliationRequired: boolean;
};

const canonicalDecisions = <TDecision extends WeeklyPlanV3VersionedDecision>(
  decisions: TDecision[],
): TDecision[] =>
  decisions
    .map((decision) => ({ ...decision }))
    .sort((left, right) => left.assignee_id - right.assignee_id);

export const weeklyPlanV3BatchPayloadFingerprint = <
  TDecision extends WeeklyPlanV3VersionedDecision,
>(
  decisions: TDecision[],
): string => JSON.stringify(canonicalDecisions(decisions));

export const resolveWeeklyPlanV3BatchConfirmation = <
  TDecision extends WeeklyPlanV3VersionedDecision,
>(
  pending: WeeklyPlanV3PendingBatchConfirmation<TDecision> | null,
  planId: number,
  currentDecisions: TDecision[],
  createRequestId: () => string,
): WeeklyPlanV3BatchConfirmationResolution<TDecision> => {
  const currentFingerprint =
    weeklyPlanV3BatchPayloadFingerprint(currentDecisions);
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
      decisions: canonicalDecisions(currentDecisions),
      payloadFingerprint: currentFingerprint,
    },
    reconciliationRequired: false,
  };
};

export const weeklyPlanV3BatchFailureMode = (
  error: unknown,
): WeeklyPlanV3BatchFailureMode => {
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

  if (status === 409) return "STALE";
  if (status === 0 || (status !== null && status >= 500)) return "AMBIGUOUS";
  if (status !== null && status >= 400 && status < 500) return "VALIDATION";

  return "AMBIGUOUS";
};
