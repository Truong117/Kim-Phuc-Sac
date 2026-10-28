export type GoogleDocsEmployeeMappingBatchInput = {
  tab_id: string;
  status: "VERIFIED" | "NEEDS_REVIEW" | "NOT_APPLICABLE";
  employee_id?: number;
  expected_version: number;
};

type RequestOptions = {
  method: string;
  body: string;
  signal?: AbortSignal;
};

type ApiRequester = <T>(path: string, options: RequestOptions) => Promise<T>;

export const requestGoogleDocsEmployeeMappingsBatch = async <T>(
  request: ApiRequester,
  planId: number,
  mappings: GoogleDocsEmployeeMappingBatchInput[],
  signal?: AbortSignal,
): Promise<T> => {
  const response = await request<{ data: T }>(
    `/api/weekly-plans/${planId}/google-docs/employee-mappings/batch`,
    {
      method: "PUT",
      body: JSON.stringify({ mappings }),
      signal,
    },
  );

  return response.data;
};
