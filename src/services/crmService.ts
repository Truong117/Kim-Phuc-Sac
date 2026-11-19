import { apiRequest } from "@/services/apiClient";

export const crmChannels = [
  "FANPAGE",
  "ZALO",
  "PHONE",
  "REFERRAL",
  "OTHER",
] as const;
export type CrmChannel = (typeof crmChannels)[number];
export type CrmStage = "NEW" | "CONSULTING" | "WAITING" | "WON" | "LOST";
export type CrmFollowUpWindow = "today" | "upcoming";
export type CrmFollowUpSummary = {
  business_date: string;
  upcoming_through: string;
  today: number;
  upcoming: number;
};
export type CrmActivityKind =
  "INTAKE" | "OPPORTUNITY" | "CONSULTATION" | "FOLLOW_UP" | "HANDOFF";

export type CrmTask = { id: number; title: string; revision: number };
export type CrmPerson = { id: number; name: string };
export type CrmOpportunity = {
  id: number;
  title: string;
  stage: CrmStage;
  version: number;
  resolution: string | null;
};
export type CrmCustomer = {
  id: number;
  code: string;
  name: string;
  channel: string | null;
  owner_name: string | null;
  owner_membership_id: number | null;
  version: number;
  next_follow_up?: { due_on: string; title: string } | null;
};
export type CrmActivity = {
  id: number;
  kind: CrmActivityKind;
  title: string;
  result: string | null;
  due_on: string | null;
  services: { name: string }[];
};
export type CrmCustomerDetail = CrmCustomer & {
  can_update: boolean;
  can_assign: boolean;
  contacts: { channel: string; value: string | null }[];
  opportunities: CrmOpportunity[];
  activities: (CrmActivity & { actor_name: string; business_date: string })[];
};
export type CrmDraft = {
  business_date: string;
  activities: (CrmActivity & {
    customer_code: string;
    customer_name: string;
  })[];
  tasks: CrmTask[];
};
export type CrmDraftSelection = {
  activity_ids: number[];
  task_id?: number;
  expected_revision?: number;
};
export type CrmFormValues = {
  name: string;
  channel: CrmChannel | "";
  contact: string;
  title: string;
  result: string;
  services: string;
  dueOn: string;
  membership: string;
  stage: CrmStage | "";
  resolution: string;
};

async function crm<T>(path: string, init?: RequestInit) {
  const response = await apiRequest<{ data: T }>("/api/crm" + path, init);
  return response.data;
}

const body = (method: "POST" | "PATCH", input: object) => ({
  method,
  body: JSON.stringify(input),
});

export const getCrmCapabilities = (signal?: AbortSignal) =>
  crm<{
    enabled: boolean;
    ready: boolean;
    can_create: boolean;
    business_date: string;
  }>("/capabilities", { signal });

export const getCrmCustomers = (
  query: string,
  page: number,
  signal?: AbortSignal,
  followUp?: CrmFollowUpWindow,
) => {
  const params = new URLSearchParams({ search: query, page: String(page) });
  if (followUp) params.set("follow_up", followUp);
  return crm<{
    data: CrmCustomer[];
    total: number;
    current_page: number;
    last_page: number;
    follow_up_summary?: CrmFollowUpSummary;
  }>("/customers?" + params, { signal });
};

export const getCrmCustomer = (id: number, signal?: AbortSignal) =>
  crm<CrmCustomerDetail>("/customers/" + id, { signal });

export const getCrmAssignees = (search: string, signal?: AbortSignal) =>
  crm<CrmPerson[]>("/assignees?" + new URLSearchParams({ search }), { signal });

export const createCrmCustomer = (requestKey: string, form: CrmFormValues) =>
  crm<{ id: number }>(
    "/customers",
    body("POST", {
      request_key: requestKey,
      name: form.name.trim(),
      channel: form.channel,
      contact: form.contact.trim() || null,
    }),
  );

export const recordCrmActivity = (
  id: number,
  kind: "OPPORTUNITY" | "CONSULTATION" | "FOLLOW_UP",
  requestKey: string,
  form: CrmFormValues,
) => {
  const details =
    kind === "CONSULTATION"
      ? {
          result: form.result.trim(),
          services: form.services
            .split(/\r?\n/)
            .map((name) => name.trim())
            .filter(Boolean),
        }
      : {
          title: form.title.trim(),
          ...(kind === "FOLLOW_UP" ? { due_on: form.dueOn } : {}),
        };
  return crm<{ id: number }>(
    "/customers/" + id + "/activities",
    body("POST", { kind, request_key: requestKey, ...details }),
  );
};

export const assignCrmCustomer = (
  customer: CrmCustomer,
  requestKey: string,
  form: CrmFormValues,
) =>
  crm(
    "/customers/" + customer.id + "/assignment",
    body("PATCH", {
      request_key: requestKey,
      version: customer.version,
      membership_id: Number(form.membership),
      reason: form.result.trim(),
    }),
  );

export const updateCrmOpportunity = (
  customerId: number,
  opportunity: CrmOpportunity,
  form: CrmFormValues,
) =>
  crm(
    "/customers/" + customerId + "/opportunities/" + opportunity.id,
    body("PATCH", {
      version: opportunity.version,
      stage: form.stage,
      resolution: form.resolution.trim() || null,
    }),
  );

export const getCrmReportDraft = (signal?: AbortSignal) =>
  crm<CrmDraft>("/report-draft", { signal });
