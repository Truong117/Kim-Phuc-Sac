import type { WeeklyPlanGoogleDocsPreview } from "../types/weeklyPlanning";

type GoogleDocsPreviewResponse = { data: WeeklyPlanGoogleDocsPreview };

export type GoogleDocsPreviewRequester = <T>(
  path: string,
  init?: RequestInit,
) => Promise<T>;

export const requestGoogleDocsPreview = async (
  request: GoogleDocsPreviewRequester,
  documentUrl: string,
  signal?: AbortSignal,
): Promise<WeeklyPlanGoogleDocsPreview> => {
  const response = await request<GoogleDocsPreviewResponse>(
    "/api/weekly-plans/google-docs/preview",
    {
      method: "POST",
      body: JSON.stringify({ document_url: documentUrl.trim() }),
      signal,
    },
  );

  return response.data;
};
