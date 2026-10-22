import type { WorkItemFormData, WorkStatus } from "@/types/reports";

export const QUICK_REPORT_MAX_ITEMS = 50;
export const QUICK_REPORT_MAX_FIELD_LENGTH = 5000;

export const QUICK_REPORT_STATUSES: WorkStatus[] = [
  "COMPLETED",
  "IN_PROGRESS",
  "BLOCKED",
];

export type QuickReportDrafts = Record<WorkStatus, string>;

export type ParsedQuickReportItem = {
  content: string;
  result: string;
};

export type QuickReportBatchError = {
  code: "content_too_long" | "result_too_long" | "item_limit";
  line?: number;
  status?: WorkStatus;
  limit: number;
};

export type QuickReportBatchResult =
  | {
      ok: true;
      items: WorkItemFormData[];
      addedCount: number;
    }
  | {
      ok: false;
      error: QuickReportBatchError;
    };

export type WorkItemSerializationLabels = {
  note: string;
  unselectedStatus: string;
  statuses: Record<WorkStatus, string>;
};

export const createEmptyQuickReportDrafts = (): QuickReportDrafts => ({
  COMPLETED: "",
  IN_PROGRESS: "",
  BLOCKED: "",
});

export const parseQuickReportText = (value: string): ParsedQuickReportItem[] =>
  value
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      const unicodeArrowIndex = line.indexOf("→");
      const asciiArrowIndex = line.indexOf("->");
      const separatorIndexes = [unicodeArrowIndex, asciiArrowIndex].filter(
        (index) => index >= 0,
      );
      const separatorIndex =
        separatorIndexes.length > 0 ? Math.min(...separatorIndexes) : -1;

      if (separatorIndex < 0) {
        return { content: line, result: "" };
      }

      const content = line.slice(0, separatorIndex).trim();
      if (!content) {
        return { content: line, result: "" };
      }

      const separatorLength = line.startsWith("->", separatorIndex) ? 2 : 1;

      return {
        content,
        result: line.slice(separatorIndex + separatorLength).trim(),
      };
    });

export const isBlankWorkItem = (item: WorkItemFormData): boolean =>
  !item.content.trim() &&
  !item.result.trim() &&
  !item.note.trim() &&
  !item.status;

export const realWorkItems = (items: WorkItemFormData[]): WorkItemFormData[] =>
  items.filter((item) => !isBlankWorkItem(item));

export const groupWorkItemsByStatus = (
  items: WorkItemFormData[],
): Record<WorkStatus, WorkItemFormData[]> => ({
  COMPLETED: items.filter((item) => item.status === "COMPLETED"),
  IN_PROGRESS: items.filter((item) => item.status === "IN_PROGRESS"),
  BLOCKED: items.filter((item) => item.status === "BLOCKED"),
});

export const buildCandidateItems = (
  currentItems: WorkItemFormData[],
  drafts: QuickReportDrafts,
  createClientId: () => string,
): QuickReportBatchResult => {
  const parsedBatches = QUICK_REPORT_STATUSES.map((status) => ({
    status,
    items: parseQuickReportText(drafts[status]),
  }));

  for (const batch of parsedBatches) {
    for (const [index, item] of batch.items.entries()) {
      if (item.content.length > QUICK_REPORT_MAX_FIELD_LENGTH) {
        return {
          ok: false,
          error: {
            code: "content_too_long",
            line: index + 1,
            status: batch.status,
            limit: QUICK_REPORT_MAX_FIELD_LENGTH,
          },
        };
      }

      if (item.result.length > QUICK_REPORT_MAX_FIELD_LENGTH) {
        return {
          ok: false,
          error: {
            code: "result_too_long",
            line: index + 1,
            status: batch.status,
            limit: QUICK_REPORT_MAX_FIELD_LENGTH,
          },
        };
      }
    }
  }

  const existingItems = realWorkItems(currentItems);
  const addedCount = parsedBatches.reduce(
    (total, batch) => total + batch.items.length,
    0,
  );

  if (existingItems.length + addedCount > QUICK_REPORT_MAX_ITEMS) {
    return {
      ok: false,
      error: {
        code: "item_limit",
        limit: QUICK_REPORT_MAX_ITEMS,
      },
    };
  }

  const appendedItems = parsedBatches.flatMap((batch) =>
    batch.items.map((item) => ({
      clientId: createClientId(),
      content: item.content,
      result: item.result,
      status: batch.status,
      note: "",
    })),
  );

  return {
    ok: true,
    items: [...existingItems, ...appendedItems],
    addedCount,
  };
};

export const serializeWorkItems = (
  items: WorkItemFormData[],
  labels: WorkItemSerializationLabels,
): string =>
  realWorkItems(items)
    .map((item) => {
      const result = item.result.trim() ? ` → ${item.result.trim()}` : "";
      const note = item.note.trim()
        ? `\n${labels.note}: ${item.note.trim()}`
        : "";
      const status = item.status
        ? labels.statuses[item.status]
        : labels.unselectedStatus;
      return `[${status}] ${item.content.trim()}${result}${note}`;
    })
    .join("\n\n");
