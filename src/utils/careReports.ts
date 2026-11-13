import type { CareReportEntry, ManagedTask, TaskOutput } from "@/types/tasks";

export const blankCareEntry = (): CareReportEntry => ({
  client_key: crypto.randomUUID(),
  activities: [{ service: "", result: "" }],
  evidence_links: [],
  attachment_ids: [],
});

export const careEntryName = (entry: CareReportEntry) =>
  entry.new_customer?.name.trim() || entry.name?.trim() || "";

export function validCareContact(value: string): boolean {
  if (!value.trim()) return true;
  if (/^\+?[\d\s().-]+$/.test(value.trim())) {
    let phone = value.replace(/[^\d+]/g, "");
    if (phone.startsWith("0")) phone = "+84" + phone.slice(1);
    else if (phone.startsWith("84")) phone = "+" + phone;
    return /^\+[1-9]\d{7,14}$/.test(phone);
  }
  try {
    const url = new URL(value.trim());
    return (
      ["http:", "https:"].includes(url.protocol) &&
      !url.username &&
      !url.password &&
      !url.port &&
      url.hostname.includes(".") &&
      !/^\d+(\.\d+){3}$/.test(url.hostname) &&
      url.pathname !== "/"
    );
  } catch {
    return false;
  }
}

export const careEntryIdentity = (entry: CareReportEntry) =>
  entry.customer_id
    ? `KH-${entry.customer_id}`
    : entry.new_customer
      ? `draft-${entry.new_customer.request_key}`
      : "";

export const careEntryOutputs = (
  entries: CareReportEntry[],
  task: ManagedTask,
): TaskOutput[] =>
  entries
    .filter((e) => careEntryIdentity(e) && careEntryName(e))
    .map((entry) => {
      const known = task.progress?.outputs.find(
        (o) =>
          o.unit === "khách hàng" &&
          (entry.legacy_output_id
            ? o.id === entry.legacy_output_id
            : entry.customer_id && o.care_customer_id === entry.customer_id),
      );
      return {
        ...(known?.id ? { id: known.id } : {}),
        name: careEntryName(entry),
        reference: known?.reference || careEntryIdentity(entry),
        completed: true,
        ...(entry.customer_id ? { care_customer_id: entry.customer_id } : {}),
      };
    });

export const careReportText = (entries: CareReportEntry[]) =>
  entries
    .filter((e) => careEntryName(e))
    .map(
      (entry) =>
        `${careEntryName(entry)} — ${entry.activities.map((a) => `${a.service.trim()}: ${a.result.trim()}`).join("; ")}`,
    )
    .join("\n");

export const careEntryPayload = (entries: CareReportEntry[]) =>
  entries.map((entry) => ({
    client_key: entry.client_key,
    ...(entry.customer_id ? { customer_id: entry.customer_id } : {}),
    ...(entry.name ? { name: entry.name } : {}),
    ...(entry.new_customer ? { new_customer: entry.new_customer } : {}),
    ...(entry.contact_update?.channel.trim() ||
    entry.contact_update?.value.trim()
      ? { contact_update: entry.contact_update }
      : {}),
    ...(entry.legacy_output_id
      ? { legacy_output_id: entry.legacy_output_id }
      : {}),
    activities: entry.activities,
    evidence_links: entry.evidence_links.filter(Boolean),
    attachment_ids: entry.attachment_ids,
  }));
