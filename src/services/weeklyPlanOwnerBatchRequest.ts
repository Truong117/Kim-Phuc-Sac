import type {
  WeeklyPlanV3OwnerBatchDecisionInput,
  WeeklyPlanV3OwnerBatchResult,
} from "@/types/weeklyPlanning";

type ApiRequester = <T>(path: string, init?: RequestInit) => Promise<T>;

export const requestWeeklyPlanOwnerBatch = async (
  request: ApiRequester,
  planId: number,
  decisions: WeeklyPlanV3OwnerBatchDecisionInput[],
  idempotencyKey: string,
): Promise<WeeklyPlanV3OwnerBatchResult> => {
  const response = await request<{ data: WeeklyPlanV3OwnerBatchResult }>(
    `/api/weekly-plans/${planId}/owner-review-batches`,
    {
      method: "POST",
      headers: { "Idempotency-Key": idempotencyKey },
      body: JSON.stringify({ decisions }),
    },
  );

  return response.data;
};
