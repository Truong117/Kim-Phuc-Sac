import { apiRequest } from "@/services/apiClient";
import type {
  ManagedTask,
  TaskInput,
  TaskListQuery,
  TaskListResponse,
  TaskReferences,
  TaskReferencesResponse,
  TaskResponse,
  TaskStatus,
} from "@/types/tasks";

const buildTaskQuery = (query: TaskListQuery) => {
  const parameters = new URLSearchParams({
    page: String(query.page),
    per_page: String(query.perPage),
  });

  const search = query.search.trim();
  if (search) parameters.set("search", search);
  if (query.status) parameters.set("status", query.status);
  if (query.priority) parameters.set("priority", query.priority);
  if (query.assigneeId) parameters.set("assignee_id", query.assigneeId);
  if (query.departmentId) parameters.set("department_id", query.departmentId);
  if (query.locationId) parameters.set("location_id", query.locationId);
  if (query.dueFrom) parameters.set("due_from", query.dueFrom);
  if (query.dueTo) parameters.set("due_to", query.dueTo);
  if (query.overdue) parameters.set("overdue", "1");

  return parameters.toString();
};

export const getTasks = (
  query: TaskListQuery,
  signal?: AbortSignal,
): Promise<TaskListResponse> =>
  apiRequest<TaskListResponse>(`/api/tasks?${buildTaskQuery(query)}`, { signal });

export const getTaskReferences = async (
  signal?: AbortSignal,
): Promise<TaskReferences> => {
  const response = await apiRequest<TaskReferencesResponse>(
    "/api/tasks/references",
    { signal },
  );

  return response.data;
};

export const getTask = async (
  taskId: string | number,
  signal?: AbortSignal,
): Promise<ManagedTask> => {
  const response = await apiRequest<TaskResponse>(
    `/api/tasks/${encodeURIComponent(String(taskId))}`,
    { signal },
  );

  return response.data;
};

export const createTask = async (input: TaskInput): Promise<ManagedTask> => {
  const response = await apiRequest<TaskResponse>("/api/tasks", {
    method: "POST",
    body: JSON.stringify(input),
  });

  return response.data;
};

export const updateTask = async (
  taskId: number,
  input: TaskInput,
): Promise<ManagedTask> => {
  const response = await apiRequest<TaskResponse>(`/api/tasks/${taskId}`, {
    method: "PATCH",
    body: JSON.stringify(input),
  });

  return response.data;
};

export const updateTaskStatus = async (
  taskId: number,
  status: TaskStatus,
): Promise<ManagedTask> => {
  const response = await apiRequest<TaskResponse>(
    `/api/tasks/${taskId}/status`,
    {
      method: "PATCH",
      body: JSON.stringify({ status }),
    },
  );

  return response.data;
};
