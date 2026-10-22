import ComponentCard from "@/components/common/ComponentCard";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import Select from "@/components/form/Select";
import TaskForm from "@/components/tasks/TaskForm";
import TaskPriorityBadge from "@/components/tasks/TaskPriorityBadge";
import TaskStatusBadge from "@/components/tasks/TaskStatusBadge";
import Button from "@/components/ui/button/Button";
import { ApiServiceError, type ValidationErrors } from "@/services/apiClient";
import {
  getTask,
  getTaskReferences,
  updateTask,
  updateTaskStatus,
} from "@/services/taskService";
import type {
  ManagedTask,
  TaskFormValues,
  TaskInput,
  TaskReferences,
  TaskStatus,
} from "@/types/tasks";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, useNavigate, useParams } from "react-router";

const formatDate = (value: string | null) => {
  if (!value) return null;
  return new Intl.DateTimeFormat("vi-VN", { timeZone: "Asia/Ho_Chi_Minh" }).format(
    new Date(`${value}T00:00:00+07:00`),
  );
};

const toValues = (task: ManagedTask): TaskFormValues => ({
  title: task.title,
  description: task.description ?? "",
  assigneeId: String(task.assignee.id),
  priority: task.priority,
  startDate: task.start_date ?? "",
  dueDate: task.due_date,
});

const toInput = (values: TaskFormValues): TaskInput => ({
  title: values.title,
  description: values.description.trim() || null,
  assignee_id: Number(values.assigneeId),
  priority: values.priority,
  start_date: values.startDate || null,
  due_date: values.dueDate,
});

export default function TaskDetail() {
  const { t } = useTranslation("common", { keyPrefix: "taskManagement" });
  const { id } = useParams();
  const navigate = useNavigate();
  const [task, setTask] = useState<ManagedTask | null>(null);
  const [references, setReferences] = useState<TaskReferences | null>(null);
  const [values, setValues] = useState<TaskFormValues | null>(null);
  const [selectedStatus, setSelectedStatus] = useState<TaskStatus>("TODO");
  const [isEditing, setIsEditing] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [validationErrors, setValidationErrors] = useState<ValidationErrors>();
  const [requestVersion, setRequestVersion] = useState(0);

  useEffect(() => {
    if (!id) return;
    const controller = new AbortController();
    Promise.all([getTask(id, controller.signal), getTaskReferences(controller.signal)])
      .then(([nextTask, nextReferences]) => {
        setTask(nextTask);
        setReferences(nextReferences);
        setValues(toValues(nextTask));
        setSelectedStatus(nextTask.status);
        setError(null);
      })
      .catch((nextError: unknown) => {
        if (nextError instanceof DOMException && nextError.name === "AbortError") return;
        if (nextError instanceof ApiServiceError && nextError.status === 404) {
          setError(t("errors.notFound"));
        } else if (nextError instanceof ApiServiceError && nextError.status === 403) {
          setError(t("errors.forbidden"));
        } else {
          setError(t("errors.loadDetail"));
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setIsLoading(false);
      });
    return () => controller.abort();
  }, [id, requestVersion, t]);

  const saveTask = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!task || !values || isSubmitting) return;
    setIsSubmitting(true);
    setActionError(null);
    setValidationErrors(undefined);
    try {
      const updated = await updateTask(task.id, toInput(values));
      setTask(updated);
      setValues(toValues(updated));
      setIsEditing(false);
    } catch (nextError) {
      if (nextError instanceof ApiServiceError) {
        setValidationErrors(nextError.validationErrors);
        setActionError(
          nextError.status === 403
            ? t("errors.manageForbidden")
            : nextError.validationErrors
              ? t("errors.validation")
              : t("errors.save"),
        );
      } else {
        setActionError(t("errors.save"));
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const saveStatus = async () => {
    if (!task || isSubmitting || selectedStatus === task.status) return;
    setIsSubmitting(true);
    setActionError(null);
    try {
      const updated = await updateTaskStatus(task.id, selectedStatus);
      setTask(updated);
      setValues(toValues(updated));
      setSelectedStatus(updated.status);
    } catch (nextError) {
      setSelectedStatus(task.status);
      if (nextError instanceof ApiServiceError && nextError.status === 403) {
        setActionError(t("errors.statusForbidden"));
      } else {
        setActionError(t("errors.saveStatus"));
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <>
      <PageMeta title={`${t("detail.title")} | KIM PHỤC SẮC`} description={t("detail.metaDescription")} />
      <PageBreadCrumb pageTitle={t("detail.title")} />

      {isLoading ? (
        <div className="h-96 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800" />
      ) : error || !task ? (
        <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
          <h2 className="text-lg font-semibold text-gray-900 dark:text-white">{t("errors.title")}</h2>
          <p className="mt-2 text-theme-sm text-error-600 dark:text-error-400">{error}</p>
          <div className="mt-5 flex justify-center gap-3">
            <Button type="button" variant="outline" onClick={() => navigate("/tasks")}>
              {t("actions.backToList")}
            </Button>
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setIsLoading(true);
                setError(null);
                setRequestVersion((value) => value + 1);
              }}
            >
              {t("actions.retry")}
            </Button>
          </div>
        </section>
      ) : (
        <div className="space-y-5">
          <div className="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5 sm:flex-row sm:items-start sm:justify-between dark:border-gray-800 dark:bg-white/3">
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <TaskStatusBadge status={task.status} />
                <TaskPriorityBadge priority={task.priority} />
                {task.is_overdue && (
                  <span className="rounded-full bg-error-50 px-2.5 py-1 text-theme-xs font-semibold text-error-700 dark:bg-error-500/15 dark:text-error-400">
                    {t("detail.overdue")}
                  </span>
                )}
              </div>
              <h1 className="mt-3 text-title-sm font-bold text-gray-900 dark:text-white">{task.title}</h1>
              <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                {t("detail.taskId", { id: task.id })}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Link
                to="/tasks"
                className="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-3 text-theme-sm font-semibold text-gray-700 transition hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
              >
                {t("actions.backToList")}
              </Link>
              {task.actions.can_manage && !isEditing && (
                <Button type="button" className="bg-kps-primary hover:bg-kps-primary-hover" onClick={() => setIsEditing(true)}>
                  {t("detail.edit")}
                </Button>
              )}
            </div>
          </div>

          {actionError && (
            <p role="alert" className="rounded-lg bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400">
              {actionError}
            </p>
          )}

          {isEditing && values && references ? (
            <ComponentCard title={t("detail.editTitle")} compact>
              <TaskForm
                values={values}
                employees={references.assignable_employees}
                validationErrors={validationErrors}
                isSubmitting={isSubmitting}
                submitLabel={t("detail.saveChanges")}
                onChange={(field, value) => {
                  setValues((current) =>
                    current ? { ...current, [field]: value } : current,
                  );
                  setValidationErrors(undefined);
                  setActionError(null);
                }}
                onSubmit={saveTask}
                onCancel={() => {
                  setValues(toValues(task));
                  setValidationErrors(undefined);
                  setActionError(null);
                  setIsEditing(false);
                }}
              />
            </ComponentCard>
          ) : (
            <div className="grid gap-5 xl:grid-cols-3">
              <ComponentCard title={t("detail.information")} compact className="xl:col-span-2">
                <p className="whitespace-pre-wrap text-theme-sm leading-6 text-gray-700 dark:text-gray-300">
                  {task.description || t("detail.noDescription")}
                </p>
                <dl className="grid gap-4 border-t border-gray-100 pt-4 sm:grid-cols-2 dark:border-gray-800">
                  {[
                    [t("detail.assignee"), task.assignee.name],
                    [t("detail.creator"), task.creator.name],
                    [t("detail.department"), task.department?.name ?? t("detail.notAvailable")],
                    [t("detail.location"), task.location?.name ?? t("detail.notAvailable")],
                    [t("detail.startDate"), formatDate(task.start_date) ?? t("detail.notAvailable")],
                    [t("detail.dueDate"), formatDate(task.due_date) ?? t("detail.notAvailable")],
                  ].map(([label, value]) => (
                    <div key={label}>
                      <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{label}</dt>
                      <dd className="mt-1 text-theme-sm font-semibold text-gray-900 dark:text-white">{value}</dd>
                    </div>
                  ))}
                </dl>
              </ComponentCard>

              <ComponentCard title={t("detail.statusTitle")} desc={t("detail.statusDescription")} compact>
                {task.actions.can_update_status ? (
                  <>
                    <Select
                      value={selectedStatus}
                      onChange={(value) => setSelectedStatus(value as TaskStatus)}
                      options={(["TODO", "IN_PROGRESS", "BLOCKED", "COMPLETED"] as TaskStatus[]).map(
                        (status) => ({ value: status, label: t(`status.${status}`) }),
                      )}
                    />
                    <Button
                      type="button"
                      disabled={isSubmitting || selectedStatus === task.status}
                      className="w-full bg-kps-primary hover:bg-kps-primary-hover disabled:bg-kps-primary"
                      onClick={() => void saveStatus()}
                    >
                      {isSubmitting ? t("form.saving") : t("detail.updateStatus")}
                    </Button>
                  </>
                ) : (
                  <p className="text-theme-sm text-gray-500 dark:text-gray-400">{t("detail.statusReadOnly")}</p>
                )}
              </ComponentCard>
            </div>
          )}
        </div>
      )}
    </>
  );
}
