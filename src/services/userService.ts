import { apiRequest } from "@/services/apiClient";
import type {
  CreateUserInput,
  ManagedUser,
  ManagedUserResponse,
  ReferenceListResponse,
  UpdateUserInput,
  UserListQuery,
  UserListResponse,
  UserReferences,
} from "@/types/userManagement";

const buildUserQuery = (query: UserListQuery) => {
  const parameters = new URLSearchParams({
    page: String(query.page),
    per_page: String(query.perPage),
  });

  const search = query.search.trim();
  if (search) parameters.set("search", search);
  if (query.departmentId) {
    parameters.set("department_id", query.departmentId);
  }
  if (query.roleId) parameters.set("role_id", query.roleId);
  if (query.status) parameters.set("status", query.status);

  return parameters.toString();
};

export const getUsers = (
  query: UserListQuery,
  signal?: AbortSignal,
): Promise<UserListResponse> =>
  apiRequest<UserListResponse>(`/api/users?${buildUserQuery(query)}`, {
    signal,
  });

export const getUser = async (
  userId: string | number,
  signal?: AbortSignal,
): Promise<ManagedUser> => {
  const response = await apiRequest<ManagedUserResponse>(
    `/api/users/${encodeURIComponent(String(userId))}`,
    { signal },
  );

  return response.data;
};

const getReferenceList = async (
  reference: "roles" | "departments" | "locations",
  signal?: AbortSignal,
) => {
  const response = await apiRequest<ReferenceListResponse>(
    `/api/reference/${reference}`,
    { signal },
  );

  return response.data;
};

export const getUserReferences = async (
  signal?: AbortSignal,
): Promise<UserReferences> => {
  const [roles, departments, locations] = await Promise.all([
    getReferenceList("roles", signal),
    getReferenceList("departments", signal),
    getReferenceList("locations", signal),
  ]);

  return { roles, departments, locations };
};

export const createUser = async (
  input: CreateUserInput,
): Promise<ManagedUser> => {
  const response = await apiRequest<ManagedUserResponse>("/api/users", {
    method: "POST",
    body: JSON.stringify(input),
  });

  return response.data;
};

export const updateUser = async (
  userId: number,
  input: UpdateUserInput,
): Promise<ManagedUser> => {
  const response = await apiRequest<ManagedUserResponse>(
    `/api/users/${userId}`,
    {
      method: "PATCH",
      body: JSON.stringify(input),
    },
  );

  return response.data;
};

export const updateUserRole = async (
  userId: number,
  roleId: number,
): Promise<ManagedUser> => {
  const response = await apiRequest<ManagedUserResponse>(
    `/api/users/${userId}/role`,
    {
      method: "PATCH",
      body: JSON.stringify({ role_id: roleId }),
    },
  );

  return response.data;
};

export const updateUserStatus = async (
  userId: number,
  isActive: boolean,
): Promise<ManagedUser> => {
  const response = await apiRequest<ManagedUserResponse>(
    `/api/users/${userId}/status`,
    {
      method: "PATCH",
      body: JSON.stringify({ is_active: isActive }),
    },
  );

  return response.data;
};
