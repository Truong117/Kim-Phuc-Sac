import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import Input from "@/components/form/input/InputField";
import TaskStatusBadge from "@/components/tasks/TaskStatusBadge";
import TaskProgressBar from "@/components/tasks/TaskProgressBar";
import TaskInlineReportEditor from "./TaskInlineReportEditor";
import {
  getAssignedReportTasks,
  type AssignedReportTasks,
} from "@/services/reportTaskService";
import { getTask } from "@/services/taskService";
import { ApiServiceError } from "@/services/apiClient";
import {
  createTaskReportItem,
  previewTaskProgress,
} from "@/utils/taskProgress";
import type { ManagedTask } from "@/types/tasks";
import type { FacebookReportPage, WorkItemFormData } from "@/types/reports";

export default function AssignedTaskReportPanel({
  date,
  userId,
  disabled,
  items,
  drafts,
  facebookPages,
  openId,
  onOpen,
  onDraftsChange,
  onApply,
}: {
  date: string;
  userId: number;
  disabled: boolean;
  items: WorkItemFormData[];
  drafts: WorkItemFormData[];
  facebookPages: FacebookReportPage[];
  openId: number | null;
  onOpen: (id: number | null) => void;
  onDraftsChange: (drafts: WorkItemFormData[]) => void;
  onApply: (item: WorkItemFormData) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "taskProgress" });
  const [result, setResult] = useState<AssignedReportTasks | null>(null);
  const [page, setPage] = useState(1),
    [version, setVersion] = useState(0),
    [search, setSearch] = useState(""),
    [query, setQuery] = useState("");
  const [error, setError] = useState(false),
    [forbidden, setForbidden] = useState(false),
    [extra, setExtra] = useState<ManagedTask | null>(null);
  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery(search);
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);
  useEffect(() => {
    const controller = new AbortController();
    getAssignedReportTasks(date, page, query, controller.signal)
      .then((data) => {
        if (!controller.signal.aborted) {
          setResult(data);
          setError(false);
          setForbidden(false);
        }
      })
      .catch((cause) => {
        if (!controller.signal.aborted) {
          setError(true);
          setForbidden(
            cause instanceof ApiServiceError && cause.status === 403,
          );
        }
      });
    return () => controller.abort();
  }, [date, page, query, version]);
  useEffect(() => {
    if (!openId) return;
    if (result?.data.some((task) => task.id === openId)) {
      document
        .getElementById(`assigned-task-${openId}`)
        ?.scrollIntoView({ block: "center", behavior: "smooth" });
      return;
    }
    const controller = new AbortController();
    getTask(openId, controller.signal)
      .then((task) => {
        if (!controller.signal.aborted) {
          if (task.assignee.id === userId && task.progress) {
            setExtra(task);
          } else setError(true);
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) setError(true);
      });
    return () => controller.abort();
  }, [openId, result, userId]);
  useEffect(() => {
    if (extra?.id === openId)
      document
        .getElementById(`assigned-task-${openId}`)
        ?.scrollIntoView({ block: "center", behavior: "smooth" });
  }, [extra, openId]);
  if (forbidden || result?.schema_ready === false) return null;
  const tasks = [
    ...(extra &&
    extra.id === openId &&
    !result?.data.some((t) => t.id === extra.id)
      ? [extra]
      : []),
    ...(result?.data ?? []),
  ];
  return (
    <ComponentCard title={t("assignedTitle")} desc={t("assignedHint")}>
      <div className="flex flex-wrap gap-3">
        <div className="w-full sm:max-w-sm">
          <Input
            aria-label={t("search")}
            placeholder={t("search")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={disabled}
          onClick={() => setVersion((v) => v + 1)}
        >
          {t("reload")}
        </Button>
      </div>
      {error && (
        <p
          role="alert"
          className="text-theme-sm text-error-600 dark:text-error-400"
        >
          {t("loadError")}
        </p>
      )}
      {!result && !error && (
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("loading")}
        </p>
      )}
      {result?.can_sync === false && (
        <p className="text-theme-xs text-warning-700 dark:text-warning-300">
          {t("historical")}
        </p>
      )}
      {tasks.length === 0 && result && (
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("noAssigned")}
        </p>
      )}
      <div className="divide-y divide-gray-200 dark:divide-gray-700">
        {tasks.map((task) => {
          const applied = items.find(
            (item) => item.taskLink?.task_id === task.id,
          );
          const draft =
            drafts.find((item) => item.taskLink?.task_id === task.id) ??
            createTaskReportItem(task, applied);
          return (
            <article
              key={task.id}
              id={`assigned-task-${task.id}`}
              className="scroll-mt-24 py-4 first:pt-0 last:pb-0"
            >
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-gray-900 dark:text-white">
                    {task.title}
                  </p>
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    <TaskStatusBadge status={task.status} />
                    <span
                      className={`text-theme-xs ${task.is_overdue ? "text-error-600 dark:text-error-400" : "text-gray-500 dark:text-gray-400"}`}
                    >
                      {t("taskDue", { date: task.due_date })}
                    </span>
                  </div>
                </div>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={disabled || !task.actions.can_update_status}
                  onClick={() => onOpen(openId === task.id ? null : task.id)}
                >
                  {t(openId === task.id ? "collapse" : "update")}
                </Button>
              </div>
              <TaskProgressBar
                progress={previewTaskProgress(
                  task,
                  result?.can_sync === false ? undefined : applied,
                )}
                pending={
                  Boolean(applied?.taskLink?.pending) &&
                  result?.can_sync !== false
                }
              />
              {applied && (
                <p className="mt-2 text-theme-xs text-kps-primary dark:text-sidebar-selected">
                  {t(
                    applied.taskLink?.pending ? "includedPending" : "included",
                  )}
                </p>
              )}
              {openId === task.id && (
                <TaskInlineReportEditor
                  key={`${task.id}:${date}`}
                  task={task}
                  value={draft}
                  canSync={result?.can_sync ?? false}
                  disabled={disabled}
                  reportDate={date}
                  facebookPages={facebookPages}
                  onChange={(value) =>
                    onDraftsChange([
                      ...drafts.filter((d) => d.taskLink?.task_id !== task.id),
                      value,
                    ])
                  }
                  onCancel={() => {
                    onDraftsChange(
                      drafts.filter((d) => d.taskLink?.task_id !== task.id),
                    );
                    onOpen(null);
                  }}
                  onApply={(value) => {
                    onApply(value);
                    onDraftsChange(
                      drafts.filter((d) => d.taskLink?.task_id !== task.id),
                    );
                    onOpen(null);
                  }}
                />
              )}
            </article>
          );
        })}
      </div>
      {(result?.meta?.last_page ?? 1) > 1 && (
        <div className="flex gap-3">
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={page <= 1 || disabled}
            onClick={() => setPage((p) => p - 1)}
          >
            {t("previous")}
          </Button>
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={page >= result!.meta!.last_page || disabled}
            onClick={() => setPage((p) => p + 1)}
          >
            {t("next")}
          </Button>
        </div>
      )}
    </ComponentCard>
  );
}
