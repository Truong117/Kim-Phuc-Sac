import type {
  DailyReport,
  LockedReportAmendment,
  LockedReportAmendmentInput,
  LockedReportAmendmentItemInput,
} from "@/types/reports";
import { normalizeNextDayPlan } from "./nextDayPlan.ts";

export const amendmentFields = ["content", "result", "note", "issue"] as const;
export type AmendmentField = (typeof amendmentFields)[number];
export type AmendmentItemDraft = Partial<Record<AmendmentField, string>>;

/** Send changed, API-authorized text fields only; never serialize report items whole. */
export function collectLockedReportChanges(
  report: Pick<DailyReport, "items" | "next_day_plan">,
  options: Pick<
    LockedReportAmendment,
    "editable_item_ids" | "can_edit_next_day_plan"
  >,
  draft: { items: Record<number, AmendmentItemDraft>; plan?: string },
) {
  const editableIds = new Set(options.editable_item_ids);
  const items: LockedReportAmendmentItemInput[] = [];
  let hasInvalidRequiredText = false;

  for (const item of report.items) {
    const entry = draft.items[item.id];
    if (!entry || !editableIds.has(item.id)) continue;

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

  const changes: Pick<LockedReportAmendmentInput, "items" | "next_day_plan"> =
    items.length ? { items } : {};
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
