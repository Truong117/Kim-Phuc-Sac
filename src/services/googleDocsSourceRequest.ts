import type { WeeklyPlan } from "../types/weeklyPlanning";

type GoogleDocsSourceResponse = { data: WeeklyPlan };

export type GoogleDocsSourceRequester = <T>(
  path: string,
  init?: RequestInit,
) => Promise<T>;

export type GoogleDocsSourceRequestGuard = { current: boolean };

export const claimGoogleDocsSourceRequest = (
  guard: GoogleDocsSourceRequestGuard,
): boolean => {
  if (guard.current) return false;

  guard.current = true;
  return true;
};

export const releaseGoogleDocsSourceRequest = (
  guard: GoogleDocsSourceRequestGuard,
): void => {
  guard.current = false;
};

export const requestGoogleDocsSource = async (
  request: GoogleDocsSourceRequester,
  documentUrl: string,
  expectedSnapshotSha256: string,
  requestId: string,
  signal?: AbortSignal,
): Promise<WeeklyPlan> => {
  const response = await request<GoogleDocsSourceResponse>(
    "/api/weekly-plans/google-docs/import",
    {
      method: "POST",
      body: JSON.stringify({
        document_url: documentUrl.trim(),
        expected_snapshot_sha256: expectedSnapshotSha256,
        request_id: requestId,
      }),
      signal,
    },
  );

  return response.data;
};
