export type AuthDisplayEntity = {
  id: number | string;
  name: string;
};

export type AuthDisplayRole = {
  name: string;
};

export type AuthUser = {
  id: number | string;
  name: string;
  email: string;
  organization: AuthDisplayEntity | null;
  department: AuthDisplayEntity | null;
  location: AuthDisplayEntity | null;
  role: AuthDisplayRole | null;
};

export type LoginCredentials = {
  email: string;
  password: string;
};
