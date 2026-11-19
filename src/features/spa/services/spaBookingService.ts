import { apiRequest } from "@/services/apiClient";

export interface SpaBookingCapabilities {
  enabled: boolean;
  viewAppointments: boolean;
  createAppointment: boolean;
  rescheduleAppointment: boolean;
  cancelAppointment: boolean;
  viewCustomers: boolean;
  createCustomer: boolean;
  editCustomer: boolean;
}

interface CustomerInput {
  name: string;
  phone: string;
  note?: string;
}

export interface SpaSavedCustomer extends CustomerInput {
  id: string;
  note: string;
  version: number;
}

export interface SpaBookingServiceOption {
  id: string;
  name: string;
  price: number;
  duration_minutes: number;
  requires_skin_photo: boolean;
}

interface ScheduleInput {
  date: string;
  start_time: string;
}

export interface SpaAppointmentEvent extends ScheduleInput {
  action: "created" | "rescheduled" | "cancelled";
  actor_name: string;
  created_at: string;
  previous_date: string | null;
  previous_start_time: string | null;
  reason: string | null;
}

export interface SpaSavedAppointment extends ScheduleInput {
  id: string;
  customer: Pick<SpaSavedCustomer, "id" | "name">;
  service: SpaBookingServiceOption;
  end_time: string;
  status: "booked" | "cancelled";
  note: string;
  version: number;
  events?: SpaAppointmentEvent[];
}

export interface SpaBookingPage<T> {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
}

const base = "/api/spa/bookings";
const capabilityKeys = [
  "enabled",
  "viewAppointments",
  "createAppointment",
  "rescheduleAppointment",
  "cancelAppointment",
  "viewCustomers",
  "createCustomer",
  "editCustomer",
] as const;

export async function getSpaBookingCapabilities(signal?: AbortSignal) {
  const result = await apiRequest<SpaBookingCapabilities>(
    `${base}/capabilities`,
    { signal },
  );
  if (!result || capabilityKeys.some((key) => typeof result[key] !== "boolean"))
    throw new Error("Invalid Spa booking capabilities response.");
  return result;
}

const list = <T>(
  path: string,
  filters: Record<string, string>,
  signal?: AbortSignal,
) =>
  apiRequest<SpaBookingPage<T>>(
    `${base}/${path}?${new URLSearchParams(filters)}`,
    {
      signal,
    },
  );

async function record<T>(path: string, init?: RequestInit): Promise<T> {
  return (await apiRequest<{ data: T }>(`${base}/${path}`, init)).data;
}

const write = <T>(
  path: string,
  method: "POST" | "PATCH",
  data: object,
  signal?: AbortSignal,
) => record<T>(path, { method, body: JSON.stringify(data), signal });

export const listSpaCustomers = (
  { search, page }: { search: string; page: number },
  signal?: AbortSignal,
) =>
  list<SpaSavedCustomer>("customers", { search, page: String(page) }, signal);

export const findSpaCustomer = (phone: string, signal?: AbortSignal) =>
  write<SpaSavedCustomer | null>("customers/lookup", "POST", { phone }, signal);

export const createSpaCustomer = (input: CustomerInput) =>
  write<SpaSavedCustomer>("customers", "POST", input);

export const updateSpaCustomer = (
  id: string,
  input: CustomerInput & { version: number },
) =>
  write<SpaSavedCustomer>(
    `customers/${encodeURIComponent(id)}`,
    "PATCH",
    input,
  );

export const listSpaAppointments = (
  { date, page }: { date: string; page: number },
  signal?: AbortSignal,
) =>
  list<SpaSavedAppointment>(
    "appointments",
    { date, page: String(page) },
    signal,
  );

export const getSpaAppointment = (id: string, signal?: AbortSignal) =>
  record<SpaSavedAppointment>(`appointments/${encodeURIComponent(id)}`, {
    signal,
  });

export const listSpaBookingServices = (
  { search, page }: { search: string; page: number },
  signal?: AbortSignal,
) =>
  list<SpaBookingServiceOption>(
    "services",
    { search, page: String(page) },
    signal,
  );

export const createSpaAppointment = (
  input: ScheduleInput & {
    id: string;
    service_id: string;
    customer_id?: string;
    customer?: CustomerInput;
    note: string;
  },
) => write<SpaSavedAppointment>("appointments", "POST", input);

export const rescheduleSpaAppointment = (
  id: string,
  input: ScheduleInput & { version: number },
) =>
  write<SpaSavedAppointment>(
    `appointments/${encodeURIComponent(id)}/reschedule`,
    "PATCH",
    input,
  );

export const cancelSpaAppointment = (
  id: string,
  input: { version: number; reason: string },
) =>
  write<SpaSavedAppointment>(
    `appointments/${encodeURIComponent(id)}/cancel`,
    "POST",
    input,
  );
