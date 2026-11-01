import type {
  WeeklyPlanV3AssistantBatchDecisionInput,
  WeeklyPlanV3AssistantBatchResult,
} from "@/types/weeklyPlanning";

type ApiRequester = <T>(path: string, init?: RequestInit) => Promise<T>;

export const requestWeeklyPlanAssistantBatch = async (
  request: ApiRequester,
  planId: number,
  decisions: WeeklyPlanV3AssistantBatchDecisionInput[],
  idempotencyKey: string,
): Promise<WeeklyPlanV3AssistantBatchResult> => {
  const response = await request<{ data: WeeklyPlanV3AssistantBatchResult }>(
    `/api/weekly-plans/${planId}/assistant-review-batches`,
    {
      method: "POST",
      headers: { "Idempotency-Key": idempotencyKey },
      body: JSON.stringify({ decisions }),
    },
  );

  return response.data;
};
