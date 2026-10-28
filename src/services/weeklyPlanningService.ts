import { apiDownload, apiRequest } from "@/services/apiClient";
import { requestGoogleDocsPreview } from "@/services/googleDocsPreviewRequest";
import {
  requestGoogleDocsEmployeeMappingsBatch,
  type GoogleDocsEmployeeMappingBatchInput,
} from "@/services/googleDocsEmployeeMappingRequest";
import { requestGoogleDocsSource } from "@/services/googleDocsSourceRequest";
import type {
  WeeklyPlan,
  WeeklyPlanAiSuggestion,
  WeeklyPlanEntryInput,
  WeeklyPlanGoogleDocsPreview,
  WeeklyPlanGoogleDocsEmployeeMappings,
  WeeklyPlanGoogleDocsMappingStatus,
  WeeklyPlanListResponse,
  WeeklyPlanReferences,
  WeeklyPlanReviewStatus,
  WeeklyPlanSourceEntryInput,
} from "@/types/weeklyPlanning";

type WeeklyPlanResponse = { data: WeeklyPlan };
type WeeklyPlanReferencesResponse = { data: WeeklyPlanReferences };

export const getWeeklyPlans = (
  page = 1,
  signal?: AbortSignal,
): Promise<WeeklyPlanListResponse> =>
  apiRequest("/api/weekly-plans?page=" + page + "&per_page=20", { signal });

export const getWeeklyPlanReferences = async (
  signal?: AbortSignal,
): Promise<WeeklyPlanReferences> => {
  const response = await apiRequest<WeeklyPlanReferencesResponse>(
    "/api/weekly-plans/references",
    { signal },
  );
  return response.data;
};

export const getWeeklyPlan = async (
  id: string | number,
  signal?: AbortSignal,
): Promise<WeeklyPlan> => {
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + encodeURIComponent(String(id)),
    { signal },
  );
  return response.data;
};

export const previewWeeklyPlanGoogleDocs = (
  documentUrl: string,
  signal?: AbortSignal,
): Promise<WeeklyPlanGoogleDocsPreview> =>
  requestGoogleDocsPreview(apiRequest, documentUrl, signal);

export const importWeeklyPlanGoogleDocs = async (
  documentUrl: string,
  expectedSnapshotSha256: string,
  requestId: string,
  signal?: AbortSignal,
): Promise<WeeklyPlan> => {
  return requestGoogleDocsSource(
    apiRequest,
    documentUrl,
    expectedSnapshotSha256,
    requestId,
    signal,
  );
};

export const getWeeklyPlanGoogleDocsEmployeeMappings = async (
  id: number,
  signal?: AbortSignal,
): Promise<WeeklyPlanGoogleDocsEmployeeMappings> => {
  const response = await apiRequest<{
    data: WeeklyPlanGoogleDocsEmployeeMappings;
  }>(`/api/weekly-plans/${id}/google-docs/employee-mappings`, { signal });

  return response.data;
};

export const updateWeeklyPlanGoogleDocsEmployeeMapping = async (
  id: number,
  input: {
    tab_id: string;
    status: Exclude<WeeklyPlanGoogleDocsMappingStatus, "UNVERIFIED">;
    employee_id: number | null;
    expected_version: number;
  },
  signal?: AbortSignal,
): Promise<WeeklyPlanGoogleDocsEmployeeMappings> => {
  const response = await apiRequest<{
    data: WeeklyPlanGoogleDocsEmployeeMappings;
  }>(`/api/weekly-plans/${id}/google-docs/employee-mappings`, {
    method: "PUT",
    body: JSON.stringify(input),
    signal,
  });

  return response.data;
};

export type WeeklyPlanGoogleDocsEmployeeMappingUpdateInput =
  GoogleDocsEmployeeMappingBatchInput;

export const updateWeeklyPlanGoogleDocsEmployeeMappingsBatch = async (
  id: number,
  mappings: WeeklyPlanGoogleDocsEmployeeMappingUpdateInput[],
  signal?: AbortSignal,
): Promise<WeeklyPlanGoogleDocsEmployeeMappings> => {
  return requestGoogleDocsEmployeeMappingsBatch<WeeklyPlanGoogleDocsEmployeeMappings>(
    apiRequest,
    id,
    mappings,
    signal,
  );
};

export const importWeeklyPlan = async (
  file: File,
  mapping: Record<string, string> = {},
): Promise<WeeklyPlan> => {
  const body = new FormData();
  body.append("file", file);
  Object.entries(mapping).forEach(([key, value]) => {
    if (value) body.append("column_mapping[" + key + "]", value);
  });
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/import",
    { method: "POST", body },
  );
  return response.data;
};

export const reparseWeeklyPlan = async (
  id: number,
  mapping: Record<string, string>,
): Promise<WeeklyPlan> => {
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + id + "/reparse",
    { method: "POST", body: JSON.stringify({ column_mapping: mapping }) },
  );
  return response.data;
};

export const extractWeeklyPlanSource = async (
  id: number,
): Promise<WeeklyPlan> => {
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + id + "/source/extract",
    { method: "POST", body: JSON.stringify({}) },
  );
  return response.data;
};

export const replaceWeeklyPlanSource = async (
  id: number,
  file: File,
): Promise<WeeklyPlan> => {
  const body = new FormData();
  body.append("file", file);
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + id + "/source/replace",
    { method: "POST", body },
  );
  return response.data;
};

export const applyWeeklyPlanSourceEntries = async (
  id: number,
  requestId: string,
  entries: WeeklyPlanSourceEntryInput[],
): Promise<WeeklyPlan> => {
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + id + "/source/apply",
    {
      method: "POST",
      body: JSON.stringify({ request_id: requestId, entries }),
    },
  );
  return response.data;
};

export const assignWeeklyPlanSourceGroup = async (
  id: number,
  passageIds: number[],
  groupTitle: string,
): Promise<WeeklyPlan> => {
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + id + "/source/group",
    {
      method: "PATCH",
      body: JSON.stringify({
        passage_ids: passageIds,
        group_title: groupTitle,
      }),
    },
  );
  return response.data;
};

export const extractWeeklyPlanSourceWithAi = async (
  id: number,
  sourcePassageIds?: number[],
): Promise<WeeklyPlanAiSuggestion[]> => {
  const response = await apiRequest<{
    data: { suggestions: WeeklyPlanAiSuggestion[] };
  }>("/api/weekly-plans/" + id + "/source/ai-extract", {
    method: "POST",
    body: JSON.stringify(
      sourcePassageIds === undefined
        ? {}
        : { source_passage_ids: sourcePassageIds },
    ),
  });
  return response.data.suggestions;
};

export const downloadWeeklyPlanTemplate = () =>
  apiDownload("/api/weekly-plans/template");

export const addWeeklyPlanEntry = async (
  id: number,
  input: WeeklyPlanEntryInput,
): Promise<WeeklyPlan> => {
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + id + "/entries",
    { method: "POST", body: JSON.stringify(input) },
  );
  return response.data;
};

export const updateWeeklyPlanEntry = async (
  id: number,
  entryId: number,
  input: WeeklyPlanEntryInput,
): Promise<WeeklyPlan> => {
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + id + "/entries/" + entryId,
    { method: "PATCH", body: JSON.stringify(input) },
  );
  return response.data;
};

export const deleteWeeklyPlanEntry = async (
  id: number,
  entryId: number,
): Promise<WeeklyPlan> => {
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + id + "/entries/" + entryId,
    { method: "DELETE" },
  );
  return response.data;
};

const planAction = async (id: number, action: string): Promise<WeeklyPlan> => {
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + id + "/" + action,
    { method: "POST", body: JSON.stringify({}) },
  );
  return response.data;
};

export const submitWeeklyPlan = (id: number) => planAction(id, "submit");
export const analyzeWeeklyPlan = (id: number) => planAction(id, "analyze");
export const approveWeeklyPlan = (id: number) => planAction(id, "approve");
export const publishWeeklyPlan = (id: number) => planAction(id, "publish");
export const approveAndPublishWeeklyPlan = (id: number) =>
  planAction(id, "approve-and-publish");

export const reviewWeeklyPlanEmployee = async (
  id: number,
  assigneeId: number,
  status: Exclude<WeeklyPlanReviewStatus, "PENDING">,
  comment?: string,
): Promise<WeeklyPlan> => {
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + id + "/employee-reviews",
    {
      method: "POST",
      body: JSON.stringify({
        assignee_id: assigneeId,
        status,
        comment: comment || null,
      }),
    },
  );
  return response.data;
};

export const decideWeeklyPlanSuggestion = async (
  id: number,
  suggestionId: number,
  status: "ACCEPTED" | "REJECTED",
): Promise<WeeklyPlan> => {
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + id + "/suggestions/" + suggestionId,
    { method: "PATCH", body: JSON.stringify({ status }) },
  );
  return response.data;
};

export const addWeeklyPlanComment = async (
  id: number,
  content: string,
  assigneeId?: number,
  entryId?: number,
): Promise<WeeklyPlan> => {
  const response = await apiRequest<WeeklyPlanResponse>(
    "/api/weekly-plans/" + id + "/comments",
    {
      method: "POST",
      body: JSON.stringify({
        content,
        assignee_id: assigneeId ?? null,
        entry_id: entryId ?? null,
      }),
    },
  );
  return response.data;
};
