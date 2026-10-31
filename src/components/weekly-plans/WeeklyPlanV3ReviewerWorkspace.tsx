import Button from "@/components/ui/button/Button";
import WeeklyPlanV3AssistantBatchReview from "@/components/weekly-plans/WeeklyPlanV3AssistantBatchReview";
import WeeklyPlanV3EmployeeGroup from "@/components/weekly-plans/WeeklyPlanV3EmployeeGroup";
import WeeklyPlanV3OwnerBatchReview from "@/components/weekly-plans/WeeklyPlanV3OwnerBatchReview";
import WeeklyPlanV3PublicationSummary from "@/components/weekly-plans/WeeklyPlanV3PublicationSummary";
import type { WeeklyPlan } from "@/types/weeklyPlanning";
import {
  createWeeklyPlanV3ReviewerWorkspaceState,
  filterWeeklyPlanV3Workflows,
  weeklyPlanV3EntriesByAssignee,
  weeklyPlanV3HasHrCorrectionAccess,
  weeklyPlanV3ReviewerWorkspaceReducer,
  type WeeklyPlanV3EmployeeFilter,
  type WeeklyPlanV3ReviewerView,
} from "@/utils/weeklyPlanV3ReviewWorkspace";
import { useCallback, useMemo, useReducer, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, useBeforeUnload } from "react-router";

type Props = {
  plan: WeeklyPlan;
  onPlanChange: (plan: WeeklyPlan) => void;
  onReload: () => Promise<void>;
};

const filters: WeeklyPlanV3EmployeeFilter[] = [
  "MY_REVIEW",
  "OTHER_REVIEW",
  "HR_CORRECTION",
  "PUBLISHED",
  "NO_WORK_APPROVED",
  "ALL",
];

export default function WeeklyPlanV3ReviewerWorkspace({
  plan,
  onPlanChange,
  onReload,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.reviewWorkspace.v3",
  });
  const workflows = useMemo(
    () => plan.employee_workflows ?? [],
    [plan.employee_workflows],
  );
  const hasAssistantWorkflow = workflows.some(
    (workflow) => workflow.viewer_context.mode === "ASSISTANT_REVIEW",
  );
  const hasOwnerWorkflow = workflows.some(
    (workflow) => workflow.viewer_context.mode === "OWNER_REVIEW",
  );
  const initialView: WeeklyPlanV3ReviewerView =
    hasAssistantWorkflow && !hasOwnerWorkflow
      ? "ASSISTANT"
      : hasOwnerWorkflow && !hasAssistantWorkflow
        ? "OWNER"
        : "OVERVIEW";
  const [workspaceState, dispatch] = useReducer(
    weeklyPlanV3ReviewerWorkspaceReducer,
    initialView,
    createWeeklyPlanV3ReviewerWorkspaceState,
  );
  const hasAssistantQueue =
    hasAssistantWorkflow || workspaceState.assistantPending !== null;
  const hasOwnerQueue =
    hasOwnerWorkflow || workspaceState.ownerPending !== null;
  const [filter, setFilter] = useState<WeeklyPlanV3EmployeeFilter>(
    workflows.some(
      (workflow) =>
        !workflow.viewer_context.read_only &&
        ["ASSISTANT_REVIEW", "OWNER_REVIEW"].includes(
          workflow.viewer_context.mode,
        ),
    )
      ? "MY_REVIEW"
      : "ALL",
  );
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const entriesByAssignee = useMemo(
    () => weeklyPlanV3EntriesByAssignee(plan.entries),
    [plan.entries],
  );
  const visibleWorkflows = useMemo(
    () => filterWeeklyPlanV3Workflows(workflows, filter),
    [filter, workflows],
  );
  const activeView =
    (workspaceState.view === "ASSISTANT" && !hasAssistantQueue) ||
    (workspaceState.view === "OWNER" && !hasOwnerQueue)
      ? "OVERVIEW"
      : workspaceState.view;
  const hasHrCorrections = weeklyPlanV3HasHrCorrectionAccess(plan);
  const hasUnresolvedRequest =
    workspaceState.assistantPending !== null ||
    workspaceState.ownerPending !== null;

  useBeforeUnload(
    useCallback(
      (event) => {
        if (!hasUnresolvedRequest) return;
        event.preventDefault();
        event.returnValue = "";
      },
      [hasUnresolvedRequest],
    ),
  );

  return (
    <div className="space-y-5" data-workspace="v3-reviewer">
      <WeeklyPlanV3PublicationSummary summary={plan.publication_summary} />

      <section className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/3">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="font-semibold text-gray-900 dark:text-white">
              {t("navigation.title")}
            </h2>
            <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
              {t("navigation.description")}
            </p>
          </div>
          {hasHrCorrections && !hasUnresolvedRequest && (
            <Link
              to={`/weekly-plans/${plan.id}/submit`}
              className="inline-flex items-center justify-center rounded-lg border border-warning-300 px-3 py-2 text-theme-sm font-semibold text-warning-700 transition hover:bg-warning-50 focus-visible:ring-3 focus-visible:ring-warning-500/25 focus-visible:outline-none dark:border-warning-700 dark:text-warning-300 dark:hover:bg-warning-500/10"
            >
              {t("navigation.openCorrections")}
            </Link>
          )}
          {hasHrCorrections && hasUnresolvedRequest && (
            <span className="inline-flex items-center rounded-lg border border-warning-300 px-3 py-2 text-theme-sm font-semibold text-warning-700 dark:border-warning-700 dark:text-warning-300">
              {t("navigation.reconcileBeforeCorrections")}
            </span>
          )}
        </div>
        <div
          className="mt-4 flex flex-wrap gap-2"
          role="tablist"
          aria-label={t("navigation.label")}
        >
          {(
            [
              ["OVERVIEW", true],
              ["ASSISTANT", hasAssistantQueue],
              ["OWNER", hasOwnerQueue],
            ] as const
          ).map(
            ([item, visible]) =>
              visible && (
                <button
                  key={item}
                  type="button"
                  role="tab"
                  aria-selected={activeView === item}
                  className={`rounded-lg px-4 py-3 text-theme-sm font-semibold transition focus-visible:ring-3 focus-visible:ring-kps-primary/25 focus-visible:outline-none ${
                    activeView === item
                      ? "bg-brand-500 text-white shadow-theme-xs hover:bg-brand-600"
                      : "bg-white text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/3"
                  }`}
                  onClick={() => dispatch({ type: "SET_VIEW", view: item })}
                >
                  {t(`navigation.${item}`)}
                </button>
              ),
          )}
        </div>
      </section>

      {hasUnresolvedRequest && (
        <p
          role="alert"
          className="rounded-xl border border-warning-200 bg-warning-50 p-4 text-theme-sm font-medium text-warning-800 dark:border-warning-800 dark:bg-warning-500/10 dark:text-warning-300"
        >
          {t("navigation.unresolvedWarning")}
        </p>
      )}

      <div hidden={activeView !== "ASSISTANT"}>
        <WeeklyPlanV3AssistantBatchReview
          plan={plan}
          onPlanChange={onPlanChange}
          onReload={onReload}
          pending={workspaceState.assistantPending}
          onPendingChange={(pending) =>
            dispatch({ type: "SET_ASSISTANT_PENDING", pending })
          }
        />
      </div>

      <div hidden={activeView !== "OWNER"}>
        <WeeklyPlanV3OwnerBatchReview
          plan={plan}
          onPlanChange={onPlanChange}
          onReload={onReload}
          pending={workspaceState.ownerPending}
          onPendingChange={(pending) =>
            dispatch({ type: "SET_OWNER_PENDING", pending })
          }
        />
      </div>

      {activeView === "OVERVIEW" && (
        <section aria-labelledby="weekly-plan-v3-employees-title">
          <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2
                id="weekly-plan-v3-employees-title"
                className="font-semibold text-gray-900 dark:text-white"
              >
                {t("overview.title")}
              </h2>
              <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                {t("overview.description")}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              {filters.map((item) => (
                <Button
                  key={item}
                  type="button"
                  size="sm"
                  variant={filter === item ? "primary" : "outline"}
                  aria-pressed={filter === item}
                  onClick={() => setFilter(item)}
                >
                  {t(`filters.${item}`)}
                </Button>
              ))}
            </div>
          </div>

          <div className="space-y-3">
            {visibleWorkflows.map((workflow) => {
              const entries = entriesByAssignee.get(workflow.assignee.id) ?? [];
              const comments = plan.comments.filter(
                (comment) =>
                  comment.assignee_id === workflow.assignee.id ||
                  entries.some((entry) => entry.id === comment.entry_id),
              );

              return (
                <WeeklyPlanV3EmployeeGroup
                  key={workflow.id}
                  workflow={workflow}
                  entries={entries}
                  comments={comments}
                  expanded={
                    expandedId === workflow.id || visibleWorkflows.length === 1
                  }
                  onToggle={() =>
                    setExpandedId((current) =>
                      current === workflow.id ? null : workflow.id,
                    )
                  }
                />
              );
            })}
          </div>

          {visibleWorkflows.length === 0 && (
            <p className="rounded-xl bg-gray-50 p-4 text-theme-sm text-gray-600 dark:bg-gray-800/60 dark:text-gray-400">
              {t("overview.empty")}
            </p>
          )}
        </section>
      )}
    </div>
  );
}
