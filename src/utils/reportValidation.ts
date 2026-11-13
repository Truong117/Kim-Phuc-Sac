import type {
  WorkItemFormData,
  WorkItemValidationErrors,
} from "@/types/reports";

export type ReportFieldErrors = {
  items: Record<string, WorkItemValidationErrors>;
  plan?: string;
};

// API indexes refer to the submitted array, not the order of the status groups on screen.
export const mapReportFieldErrors = (
  errors: Record<string, string[]> | undefined,
  submittedItems: WorkItemFormData[],
): ReportFieldErrors => {
  const mapped: ReportFieldErrors = { items: {} };
  for (const [path, messages] of Object.entries(errors ?? {})) {
    const message = messages[0];
    if (!message) continue;
    if (path === "next_day_plan") {
      mapped.plan = message;
      continue;
    }
    const taskMatch = /^items\.(\d+)\.(task_id|task_progress)(?:\..*)?$/.exec(
      path,
    );
    if (taskMatch) {
      const row = submittedItems[Number(taskMatch[1])];
      if (row) (mapped.items[row.clientId] ??= {}).content ??= message;
      continue;
    }
    const match =
      /^items\.(\d+)\.(content|result|status|note|issue|evidence_links|facebook_selections)(?:\.\d+(?:\.[a-z_]+)?)?$/.exec(
        path,
      );
    const item = match ? submittedItems[Number(match[1])] : undefined;
    if (!match || !item) continue;
    const field = ["evidence_links", "facebook_selections"].includes(match[2])
      ? "evidenceLinks"
      : (match[2] as keyof WorkItemValidationErrors);
    const itemErrors = (mapped.items[item.clientId] ??= {});
    itemErrors[field] ??= message;
  }
  return mapped;
};

export const focusFirstReportError = (root: HTMLElement | null): void => {
  requestAnimationFrame(() => {
    if (!root?.isConnected) return;
    const target =
      root.querySelector<HTMLElement>('[aria-invalid="true"]:not(:disabled)') ??
      root.querySelector<HTMLElement>("[data-report-error-summary]");
    target?.focus({ preventScroll: true });
    (target?.closest("fieldset") ?? target)?.scrollIntoView({
      behavior: "smooth",
      block: "center",
      inline: "nearest",
    });
  });
};
