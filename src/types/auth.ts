export type AuthUser = {
  id: number | string;
  name: string;
  email: string;
};

export type LoginCredentials = {
  email: string;
  password: string;
};
