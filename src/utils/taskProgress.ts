import type {
  ManagedTask,
  ReportTaskLink,
  TaskOutput,
  TaskProgress,
  TaskQuantityUnit,
  CareCustomerSelection,
} from "@/types/tasks";
import type { WorkItemFormData } from "@/types/reports";

export const outputIdentity = (name: string) =>
  name.normalize("NFKC").trim().replace(/\s+/g, " ").toLocaleLowerCase("vi");

export const needsOutputReference = (
  unit: TaskQuantityUnit | null | undefined,
) => unit === "khách hàng" || unit === "lượt chăm sóc";
export const taskOutputIdentity = (
  output: TaskOutput,
  unit: TaskQuantityUnit | null | undefined,
) =>
  outputIdentity(
    needsOutputReference(unit) ? (output.reference ?? "") : output.name,
  );

export function namedVideoSuggestions(text: string): string[] {
  if (
    !/hoàn thành|hoàn thiện|đã (dựng|edit|biên tập)/iu.test(text) ||
    /chưa|không|dự kiến|kế hoạch|sẽ |đang |cần |chờ |nhắc lại|đã báo cáo|lũy kế|luỹ kế/iu.test(
      text,
    )
  )
    return [];
  const quoted =
    '(?:"[^"\\r\\n]{1,200}"|“[^”\\r\\n]{1,200}”|‘[^’\\r\\n]{1,200}’)';
  const list = new RegExp(
    "(?:hoàn thành|hoàn thiện|đã (?:dựng|edit|biên tập))\\s+(?:xong\\s+)?(?:các\\s+)?(?:\\d+\\s+)?(?:video|clip)\\s*(?::\\s*(?:(?:video|clip)\\s+)?)?(" +
      quoted +
      "(?:\\s*(?:,|;|và|&)\\s*(?:(?:video|clip)\\s*)?" +
      quoted +
      ")*)",
    "giu",
  );
  const names = new Map<string, string>();
  for (const match of text.matchAll(list)) {
    for (const name of match[1].matchAll(
      /"([^"\r\n]+)"|“([^”\r\n]+)”|‘([^’\r\n]+)’/gu,
    )) {
      const value = (name[1] || name[2] || name[3]).trim();
      if (value && !/https?:\/\//iu.test(value))
        names.set(outputIdentity(value), value);
    }
  }
  return [...names.values()];
}

export function createTaskReportItem(
  task: ManagedTask,
  existing?: WorkItemFormData,
): WorkItemFormData {
  const item = existing
    ? structuredClone(existing)
    : {
        clientId: `assigned-task-${task.id}`,
        content: task.title,
        result: "",
        status: "IN_PROGRESS" as const,
        note: "",
        issue: "",
        evidenceLinks: "",
      };
  const link: ReportTaskLink = item.taskLink ?? {
    task_id: task.id,
    snapshot: {
      id: task.id,
      title: task.title,
      description: task.description,
      due_date: task.due_date,
      target_quantity: task.target_quantity,
      quantity_unit: task.quantity_unit,
    },
    progress: {
      input: {
        expected_revision: task.progress?.revision ?? 0,
        status: "KEEP",
        unit: task.quantity_unit ?? task.progress?.unit ?? "video",
        outputs: [],
        ...((task.quantity_unit ?? task.progress?.unit) === "khách hàng"
          ? {
              care_work_items: [
                {
                  client_key: crypto.randomUUID(),
                  services: [],
                  customers: [],
                  result: "",
                  evidence_links: [],
                  attachment_ids: [],
                },
              ],
            }
          : {}),
      },
    },
  };
  item.result = link.progress.source_result ?? item.result;
  item.taskLink = structuredClone(link);
  return item;
}

export function previewTaskProgress(
  task: ManagedTask,
  item?: WorkItemFormData,
): TaskProgress | null {
  if (!task.progress) return null;
  const p = structuredClone(task.progress);
  if (!item?.taskLink?.pending) return p;
  const update = item.taskLink.progress.input;
  const unit = update.unit;
  const outputs = new Map(
    p.outputs
      .filter((o) => o.unit === unit)
      .map((o) => [o.id ? `id:${o.id}` : taskOutputIdentity(o, unit), o]),
  );
  for (const output of update.outputs) {
    const previous = p.outputs.find(
      (o) =>
        o.unit === unit &&
        (output.id
          ? output.id === o.id
          : taskOutputIdentity(output, unit) === taskOutputIdentity(o, unit)),
    );
    outputs.set(
      previous?.id ? `id:${previous.id}` : taskOutputIdentity(output, unit),
      output,
    );
  }
  if (unit)
    p.counts[unit] = [...outputs.values()].filter((o) => o.completed).length;
  p.unit = task.quantity_unit ?? unit;
  p.completed = p.unit ? (p.counts[p.unit] ?? 0) : 0;
  p.percent =
    task.target_quantity && task.quantity_unit
      ? Math.min(
          100,
          Math.round((p.completed * 1000) / task.target_quantity) / 10,
        )
      : null;
  return p;
}

export function validTaskLink(value: unknown): value is ReportTaskLink {
  if (!value || typeof value !== "object") return false;
  const link = value as ReportTaskLink;
  const input = link.progress?.input;
  return (
    (link.pending === undefined || typeof link.pending === "boolean") &&
    (link.progress?.source_result === undefined ||
      typeof link.progress.source_result === "string") &&
    (input?.unit === null ||
      [
        "video",
        "ảnh",
        "bài viết",
        "sản phẩm",
        "khách hàng",
        "lượt chăm sóc",
      ].includes(input?.unit)) &&
    Number.isInteger(link.task_id) &&
    link.task_id > 0 &&
    typeof link.snapshot?.title === "string" &&
    link.snapshot.id === link.task_id &&
    Boolean(input) &&
    Number.isInteger(input.expected_revision) &&
    input.expected_revision >= 0 &&
    ["KEEP", "IN_PROGRESS", "COMPLETED", "BLOCKED"].includes(input.status) &&
    Array.isArray(input.outputs) &&
    (input.care_entries === undefined ||
      (Array.isArray(input.care_entries) &&
        input.care_entries.length <= 20 &&
        input.care_entries.every(
          (e) =>
            validCareCustomer(e) &&
            Array.isArray(e.activities) &&
            e.activities.length <= 10 &&
            e.activities.every(
              (a) =>
                a &&
                typeof a.service === "string" &&
                typeof a.result === "string",
            ) &&
            validCareFiles(e),
        ))) &&
    (input.care_work_items === undefined ||
      (Array.isArray(input.care_work_items) &&
        input.care_work_items.length <= 20 &&
        input.care_work_items.every(
          (w) =>
            w &&
            typeof w.client_key === "string" &&
            typeof w.result === "string" &&
            Array.isArray(w.services) &&
            w.services.length <= 10 &&
            w.services.every(
              (s) =>
                s &&
                typeof s.name === "string" &&
                (s.id == null || (Number.isInteger(s.id) && s.id > 0)),
            ) &&
            Array.isArray(w.customers) &&
            w.customers.length <= 20 &&
            w.customers.every(validCareCustomer) &&
            validCareFiles(w),
        ))) &&
    input.outputs.length <= 50 &&
    input.outputs.every(
      (o: TaskOutput) =>
        Boolean(o) &&
        typeof o.name === "string" &&
        (o.reference == null ||
          (typeof o.reference === "string" && o.reference.length <= 200)) &&
        o.name.length <= 200 &&
        typeof o.completed === "boolean" &&
        (o.id == null || (Number.isInteger(o.id) && o.id > 0)),
    )
  );
}

function validCareCustomer(value: unknown): value is CareCustomerSelection {
  if (!value || typeof value !== "object") return false;
  const c = value as CareCustomerSelection;
  return (
    typeof c.client_key === "string" &&
    (c.customer_id === undefined ||
      (Number.isInteger(c.customer_id) && c.customer_id > 0)) &&
    (c.name === undefined || typeof c.name === "string") &&
    (c.note === undefined || typeof c.note === "string") &&
    (!c.new_customer ||
      (typeof c.new_customer.request_key === "string" &&
        typeof c.new_customer.name === "string" &&
        typeof c.new_customer.channel === "string" &&
        (c.new_customer.contact === undefined ||
          typeof c.new_customer.contact === "string"))) &&
    (!c.contact_update ||
      (typeof c.contact_update.channel === "string" &&
        typeof c.contact_update.value === "string")) &&
    (c.legacy_output_id === undefined ||
      (Number.isInteger(c.legacy_output_id) && c.legacy_output_id > 0))
  );
}
function validCareFiles(value: {
  evidence_links?: unknown;
  attachment_ids?: unknown;
  attachment_names?: unknown;
}): boolean {
  return (
    Array.isArray(value.evidence_links) &&
    value.evidence_links.length <= 3 &&
    value.evidence_links.every((v: unknown) => typeof v === "string") &&
    Array.isArray(value.attachment_ids) &&
    value.attachment_ids.length <= 3 &&
    value.attachment_ids.every(
      (v: unknown) => Number.isInteger(v) && Number(v) > 0,
    ) &&
    (value.attachment_names === undefined ||
      (value.attachment_names !== null &&
        typeof value.attachment_names === "object" &&
        Object.values(value.attachment_names).every(
          (v) => typeof v === "string",
        )))
  );
}
