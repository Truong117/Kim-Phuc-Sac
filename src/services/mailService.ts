import { apiRequest } from "./apiClient";

export interface MailCapabilities {
  can_view: boolean;
  can_configure: boolean;
}
export interface MailHistoryEntry {
  id: string;
  recipient_user_id: string;
  created_by: string;
  recipient_email: string;
  subject?: string;
  kind: string;
  status: string;
  error_code: string | null;
  created_at: string;
  accepted_at: string | null;
}
export interface MailStatus {
  data: {
    enabled: boolean;
    configured: boolean;
    schema_ready: boolean;
    send_enabled: boolean;
    expected_email: string;
    status: string;
    email: string | null;
    connected_at: string | null;
    from_name: string;
    history: MailHistoryEntry[];
  };
  actions: { can_configure: boolean };
}
export interface MailRecipient {
  id: string;
  name: string;
  email: string;
  department: string | null;
  test_allowed: boolean;
  send_allowed: boolean;
}
export const getMailCapabilities = (signal?: AbortSignal) =>
  apiRequest<MailCapabilities>("/api/integrations/mail/capabilities", {
    signal,
  });
export const getMailStatus = (signal?: AbortSignal) =>
  apiRequest<MailStatus>("/api/integrations/mail", { signal });
export const getMailRecipients = (signal?: AbortSignal) =>
  apiRequest<{ data: MailRecipient[] }>("/api/integrations/mail/recipients", {
    signal,
  });
export const connectGmail = () =>
  apiRequest<{ authorization_url: string }>("/api/integrations/mail/connect", {
    method: "POST",
  });
export const disconnectGmail = () =>
  apiRequest<void>("/api/integrations/mail/connection", { method: "DELETE" });
export const sendMailTest = (recipientId: string, requestKey: string) =>
  apiRequest<{ data: MailHistoryEntry }>("/api/integrations/mail/test", {
    method: "POST",
    body: JSON.stringify({
      recipient_user_id: Number(recipientId),
      request_key: requestKey,
    }),
  });

export const queueMailTests = (
  recipients: { recipient_user_id: number; request_key: string }[],
) =>
  apiRequest<{ data: MailHistoryEntry[] }>("/api/integrations/mail/tests", {
    method: "POST",
    body: JSON.stringify({ recipients }),
  });
export const processMailTest = (id: string) =>
  apiRequest<{ data: MailHistoryEntry }>(
    `/api/integrations/mail/tests/${encodeURIComponent(id)}/process`,
    { method: "POST" },
  );

export const queueMailMessages = (
  recipients: { recipient_user_id: number; request_key: string }[],
  subject: string,
  body: string,
) =>
  apiRequest<{ data: MailHistoryEntry[] }>("/api/integrations/mail/messages", {
    method: "POST",
    body: JSON.stringify({ recipients, subject, body }),
  });
export const processMailMessage = (id: string) =>
  apiRequest<{ data: MailHistoryEntry }>(
    `/api/integrations/mail/messages/${encodeURIComponent(id)}/process`,
    { method: "POST" },
  );
