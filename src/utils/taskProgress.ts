import type {
  ManagedTask,
  ReportTaskLink,
  TaskOutput,
  TaskProgress,
} from "@/types/tasks";
import type { WorkItemFormData } from "@/types/reports";

export const outputIdentity = (name: string) =>
  name.normalize("NFKC").trim().replace(/\s+/g, " ").toLocaleLowerCase("vi");

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
    "(?:video|clip)\\s+(" +
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
      .map((o) => [o.id ? `id:${o.id}` : outputIdentity(o.name), o]),
  );
  for (const output of update.outputs) {
    const previous = p.outputs.find(
      (o) =>
        o.unit === unit &&
        (output.id
          ? output.id === o.id
          : outputIdentity(output.name) === outputIdentity(o.name)),
    );
    outputs.set(
      previous?.id ? `id:${previous.id}` : outputIdentity(output.name),
      output,
    );
  }
  if (unit)
    p.counts[unit] = [...outputs.values()].filter((o) => o.completed).length;
  p.unit = task.quantity_unit ?? unit;
  p.completed = p.unit ? (p.counts[p.unit] ?? 0) : 0;
  p.percent =
    task.target_quantity && task.quantity_unit
      ? Math.min(100, Math.floor((p.completed * 100) / task.target_quantity))
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
      ["video", "ảnh", "bài viết", "sản phẩm"].includes(input?.unit)) &&
    Number.isInteger(link.task_id) &&
    link.task_id > 0 &&
    typeof link.snapshot?.title === "string" &&
    link.snapshot.id === link.task_id &&
    Boolean(input) &&
    Number.isInteger(input.expected_revision) &&
    input.expected_revision >= 0 &&
    ["KEEP", "IN_PROGRESS", "COMPLETED", "BLOCKED"].includes(input.status) &&
    Array.isArray(input.outputs) &&
    input.outputs.length <= 50 &&
    input.outputs.every(
      (o: TaskOutput) =>
        typeof o.name === "string" &&
        o.name.length <= 200 &&
        typeof o.completed === "boolean" &&
        (o.id == null || (Number.isInteger(o.id) && o.id > 0)),
    )
  );
}
