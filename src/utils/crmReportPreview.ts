import type { CrmDraft, CrmTask } from "@/services/crmService";
import type { WorkItemFormData, WorkStatus } from "@/types/reports";

type Activity = CrmDraft["activities"][number];

export type CrmReportPreviewRow =
  | { kind: "activity"; activity: Activity }
  | {
      kind: "care";
      task: CrmTask;
      activities: Activity[];
      existing: boolean;
    };

export function buildCrmReportPreview(
  activities: Activity[],
  task: CrmTask | undefined,
  items: WorkItemFormData[],
  existingPlan: string[],
  followUpLabel: string,
) {
  const groups: Record<
    WorkStatus,
    { rows: CrmReportPreviewRow[]; count: number }
  > = {
    COMPLETED: { rows: [], count: 0 },
    IN_PROGRESS: { rows: [], count: 0 },
    BLOCKED: { rows: [], count: 0 },
  };
  const consultations: Activity[] = [];
  const plans = new Set<string>();

  for (const activity of activities) {
    if (activity.kind === "FOLLOW_UP") {
      plans.add(
        `${followUpLabel} ${activity.customer_code} — ${activity.title}`,
      );
      continue;
    }
    if (activity.kind === "CONSULTATION" && task) {
      consultations.push(activity);
      continue;
    }
    const status =
      activity.kind === "OPPORTUNITY" ? "IN_PROGRESS" : "COMPLETED";
    groups[status].rows.push({ kind: "activity", activity });
    groups[status].count += 1;
  }

  if (task && consultations.length) {
    const existing = items.find((item) => item.taskLink?.task_id === task.id);
    const status = existing?.status || "COMPLETED";
    groups[status].rows.push({
      kind: "care",
      task,
      activities: consultations,
      existing: Boolean(existing),
    });
    if (!existing) groups[status].count += 1;
  }

  return {
    groups,
    plans: [...plans].filter((line) => !existingPlan.includes(line)),
  };
}
