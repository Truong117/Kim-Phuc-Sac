export type UserRole = "ADMIN" | "MANAGER" | "EMPLOYEE";

export interface CurrentUser {
  id: string;
  name: string;
  department: string;
  role: UserRole;
  avatar?: string;
}
