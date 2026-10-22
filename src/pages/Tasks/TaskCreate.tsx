import ComponentCard from "@/components/common/ComponentCard";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import TaskForm from "@/components/tasks/TaskForm";
import Button from "@/components/ui/button/Button";
import { ApiServiceError, type ValidationErrors } from "@/services/apiClient";
import { createTask, getTaskReferences } from "@/services/taskService";
import type { TaskFormValues, TaskInput, TaskReferences } from "@/types/tasks";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

const initialValues: TaskFormValues = {
  title: "",
  description: "",
  assigneeId: "",
  priority: "MEDIUM",
  startDate: "",
  dueDate: "",
};

const toInput = (values: TaskFormValues): TaskInput => ({
  title: values.title,
  description: values.description.trim() || null,
  assignee_id: Number(values.assigneeId),
  priority: values.priority,
  start_date: values.startDate || null,
  due_date: values.dueDate,
});

export default function TaskCreate() {
  const { t } = useTranslation("common", { keyPrefix: "taskManagement" });
  const navigate = useNavigate();
  const [references, setReferences] = useState<TaskReferences | null>(null);
  const [values, setValues] = useState(initialValues);
  const [isLoading, setIsLoading] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [validationErrors, setValidationErrors] = useState<ValidationErrors>();
  const [requestVersion, setRequestVersion] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    getTaskReferences(controller.signal)
      .then((response) => {
        setReferences(response);
        setError(response.actions.can_create ? null : t("errors.cannotCreate"));
      })
      .catch((nextError: unknown) => {
        if (nextError instanceof DOMException && nextError.name === "AbortError") return;
        setError(t("errors.loadReferences"));
      })
      .finally(() => {
        if (!controller.signal.aborted) setIsLoading(false);
      });
    return () => controller.abort();
  }, [requestVersion, t]);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (isSubmitting || !references?.actions.can_create) return;
    setIsSubmitting(true);
    setError(null);
    setValidationErrors(undefined);
    try {
      const task = await createTask(toInput(values));
      navigate(`/tasks/${task.id}`);
    } catch (nextError) {
      if (nextError instanceof ApiServiceError) {
        setValidationErrors(nextError.validationErrors);
        setError(
          nextError.status === 403
            ? t("errors.cannotCreate")
            : nextError.validationErrors
              ? t("errors.validation")
              : t("errors.save"),
        );
      } else {
        setError(t("errors.save"));
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <>
      <PageMeta title={`${t("create.title")} | KIM PHỤC SẮC`} description={t("create.metaDescription")} />
      <PageBreadCrumb pageTitle={t("create.title")} />
      <p className="-mt-4 mb-6 text-theme-sm text-gray-500 dark:text-gray-400">
        {t("create.subtitle")}
      </p>

      {isLoading ? (
        <div className="h-96 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800" />
      ) : error && !references?.actions.can_create ? (
        <section className="rounded-2xl border border-warning-200 bg-white px-6 py-12 text-center dark:border-warning-800 dark:bg-white/3">
          <h2 className="text-lg font-semibold text-gray-900 dark:text-white">{t("errors.title")}</h2>
          <p className="mt-2 text-theme-sm text-warning-700 dark:text-warning-400">{error}</p>
          <Button
            type="button"
            variant="outline"
            className="mt-5"
            onClick={() => {
              setIsLoading(true);
              setError(null);
              setRequestVersion((version) => version + 1);
            }}
          >
            {t("actions.retry")}
          </Button>
        </section>
      ) : references ? (
        <ComponentCard title={t("create.cardTitle")} desc={t("create.cardDescription")} compact>
          {error && (
            <p role="alert" className="rounded-lg bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400">
              {error}
            </p>
          )}
          <TaskForm
            values={values}
            employees={references.assignable_employees}
            validationErrors={validationErrors}
            isSubmitting={isSubmitting}
            submitLabel={t("create.submit")}
            onChange={(field, value) => {
              setValues((current) => ({ ...current, [field]: value }));
              setValidationErrors(undefined);
              setError(null);
            }}
            onSubmit={handleSubmit}
            onCancel={() => navigate("/tasks")}
          />
        </ComponentCard>
      ) : null}
    </>
  );
}
