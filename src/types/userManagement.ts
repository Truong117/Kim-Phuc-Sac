export type ReferenceItem = {
  id: number;
  name: string;
};

export type ManagedUser = {
  id: number;
  name: string;
  email: string;
  department: ReferenceItem | null;
  location: ReferenceItem | null;
  role: ReferenceItem | null;
  is_active: boolean;
};

export type UserStatusFilter = "" | "active" | "inactive";

export type UserListFilters = {
  search: string;
  departmentId: string;
  roleId: string;
  status: UserStatusFilter;
};

export type UserListQuery = UserListFilters & {
  page: number;
  perPage: number;
};

export type UserListMeta = {
  current_page: number;
  per_page: number;
  last_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
};

export type UserListResponse = {
  data: ManagedUser[];
  meta: UserListMeta;
};

export type ReferenceListResponse = {
  data: ReferenceItem[];
};

export type ManagedUserResponse = {
  data: ManagedUser;
};

export type UserReferences = {
  roles: ReferenceItem[];
  departments: ReferenceItem[];
  locations: ReferenceItem[];
};

export type CreateUserInput = {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  department_id: number | null;
  location_id: number | null;
  role_id: number;
};

export type UpdateUserInput = {
  name: string;
  email: string;
  department_id: number | null;
  location_id: number | null;
};

export type EmployeeFormValues = {
  name: string;
  email: string;
  password: string;
  passwordConfirmation: string;
  departmentId: string;
  locationId: string;
  roleId: string;
};
