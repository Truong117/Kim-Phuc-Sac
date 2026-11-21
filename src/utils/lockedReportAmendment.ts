import type {
  DailyReport,
  LockedReportAmendment,
  LockedReportAmendmentInput,
  LockedReportAmendmentItemInput,
  LockedReportAmendmentNewItemInput,
  WorkStatus,
} from "@/types/reports";
import { normalizeNextDayPlan } from "./nextDayPlan.ts";
import { hasValidEvidenceLinks, parseEvidenceLinks } from "./reportEvidence.ts";

export const amendmentFields = ["content", "result", "note", "issue"] as const;
export type AmendmentField = (typeof amendmentFields)[number];
export type AmendmentItemDraft = Partial<Record<AmendmentField, string>>;

export const lockedAmendmentItemLimit = 50;
export type NewAmendmentItemDraft = Record<AmendmentField, string> & {
  key: number;
  status: WorkStatus;
  evidence: string;
};
export type NewAmendmentItemErrors = Partial<
  Record<"content" | "result" | "evidence", "required" | "invalid">
>;

export function newAmendmentItemErrors(
  item: NewAmendmentItemDraft,
): NewAmendmentItemErrors {
  const completed = item.status === "COMPLETED";
  const errors: NewAmendmentItemErrors = {};
  if (!item.content.trim()) errors.content = "required";
  if (completed && !item.result.trim()) errors.result = "required";
  if (completed && !parseEvidenceLinks(item.evidence).length) {
    errors.evidence = "required";
  } else if (!hasValidEvidenceLinks(item.evidence)) {
    errors.evidence = "invalid";
  }
  return errors;
}

function serializeNewItem(
  item: NewAmendmentItemDraft,
): LockedReportAmendmentNewItemInput {
  const evidence = parseEvidenceLinks(item.evidence);
  return {
    status: item.status,
    content: item.content.trim(),
    ...(item.result.trim() ? { result: item.result.trim() } : {}),
    ...(item.note.trim() ? { note: item.note.trim() } : {}),
    ...(item.issue.trim() ? { issue: item.issue.trim() } : {}),
    ...(evidence.length ? { evidence_links: evidence } : {}),
  };
}

/** Send changed, API-authorized text fields only; never serialize report items whole. */
export function collectLockedReportChanges(
  report: Pick<DailyReport, "items" | "next_day_plan">,
  options: Pick<
    LockedReportAmendment,
    | "editable_item_ids"
    | "can_edit_next_day_plan"
    | "can_add_items"
    | "deletable_item_ids"
  >,
  draft: {
    items: Record<number, AmendmentItemDraft>;
    plan?: string;
    addedItems?: NewAmendmentItemDraft[];
    deletedItemIds?: Set<number>;
  },
) {
  const editableIds = new Set(options.editable_item_ids);
  const deletableIds = new Set(options.deletable_item_ids);
  const deletedIds = report.items
    .filter(({ id }) => deletableIds.has(id) && draft.deletedItemIds?.has(id))
    .map(({ id }) => id);
  const deleted = new Set(deletedIds);
  const newItems = options.can_add_items ? (draft.addedItems ?? []) : [];
  const items: LockedReportAmendmentItemInput[] = [];
  let hasInvalidRequiredText = false;

  for (const item of report.items) {
    const entry = draft.items[item.id];
    if (!entry || !editableIds.has(item.id) || deleted.has(item.id)) continue;

    const change: LockedReportAmendmentItemInput = { id: item.id };
    for (const field of amendmentFields) {
      const value = entry[field]?.trim();
      if (value === undefined || value === (item[field] ?? "").trim()) continue;
      if (field === "content" || field === "result") {
        if (!value && (field === "content" || item.status === "COMPLETED"))
          hasInvalidRequiredText = true;
        change[field] = value;
      } else {
        change[field] = value || null;
      }
    }
    if (Object.keys(change).length > 1) items.push(change);
  }

  const changes: Omit<
    LockedReportAmendmentInput,
    "expected_revision" | "reason"
  > = {
    ...(items.length ? { items } : {}),
    ...(deletedIds.length ? { deleted_item_ids: deletedIds } : {}),
    ...(newItems.length ? { added_items: newItems.map(serializeNewItem) } : {}),
  };
  const plan =
    draft.plan === undefined ? undefined : normalizeNextDayPlan(draft.plan);
  if (
    options.can_edit_next_day_plan &&
    plan !== undefined &&
    plan !== normalizeNextDayPlan(report.next_day_plan ?? "")
  ) {
    if (!plan) hasInvalidRequiredText = true;
    changes.next_day_plan = plan;
  }

  return {
    changes,
    hasChanges: Object.keys(changes).length > 0,
    hasInvalidRequiredText,
  };
}
