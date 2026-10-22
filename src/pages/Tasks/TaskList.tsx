import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import TaskFilters from "@/components/tasks/TaskFilters";
import TaskSummary from "@/components/tasks/TaskSummary";
import TaskTable from "@/components/tasks/TaskTable";
import WorkManagementTabs from "@/components/tasks/WorkManagementTabs";
import Button from "@/components/ui/button/Button";
import { PlusIcon } from "@/icons";
import { ApiServiceError } from "@/services/apiClient";
import { getTaskReferences, getTasks } from "@/services/taskService";
import type {
  TaskListFilters,
  TaskListResponse,
  TaskReferences,
} from "@/types/tasks";
import { useCallback, useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

const PAGE_SIZE = 20;
const initialFilters: TaskListFilters = {
  search: "",
  status: "",
  priority: "",
  assigneeId: "",
  departmentId: "",
  locationId: "",
  dueFrom: "",
  dueTo: "",
  overdue: false,
};
const initialReferences: TaskReferences = {
  employees: { visible: false, options: [] },
  departments: { visible: false, options: [] },
  locations: { visible: false, options: [] },
  assignable_employees: [],
  actions: { can_create: false },
};
const isAbortError = (error: unknown) =>
  error instanceof DOMException && error.name === "AbortError";

export default function TaskList() {
  const { t } = useTranslation("common", { keyPrefix: "taskManagement" });
  const [filters, setFilters] = useState(initialFilters);
  const [queryFilters, setQueryFilters] = useState(initialFilters);
  const [currentPage, setCurrentPage] = useState(1);
  const [result, setResult] = useState<TaskListResponse | null>(null);
  const [references, setReferences] = useState(initialReferences);
  const [isLoading, setIsLoading] = useState(true);
  const [isReferenceLoading, setIsReferenceLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [requestVersion, setRequestVersion] = useState(0);

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setQueryFilters({ ...filters });
      setCurrentPage(1);
      setIsLoading(true);
    }, 300);

    return () => window.clearTimeout(timeout);
  }, [filters]);

  useEffect(() => {
    const controller = new AbortController();
    getTaskReferences(controller.signal)
      .then(setReferences)
      .catch((nextError: unknown) => {
        if (!isAbortError(nextError)) setReferences(initialReferences);
      })
      .finally(() => {
        if (!controller.signal.aborted) setIsReferenceLoading(false);
      });

    return () => controller.abort();
  }, [requestVersion]);

  useEffect(() => {
    const controller = new AbortController();
    getTasks(
      { ...queryFilters, page: currentPage, perPage: PAGE_SIZE },
      controller.signal,
    )
      .then((response) => {
        setResult(response);
        setError(null);
      })
      .catch((nextError: unknown) => {
        if (isAbortError(nextError)) return;
        if (nextError instanceof ApiServiceError && nextError.status === 403) {
          setError(t("errors.forbidden"));
        } else if (nextError instanceof ApiServiceError && nextError.status === 0) {
          setError(t("errors.network"));
        } else {
          setError(t("errors.load"));
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setIsLoading(false);
      });

    return () => controller.abort();
  }, [currentPage, queryFilters, requestVersion, t]);

  const handleChange = useCallback(
    <Key extends keyof TaskListFilters>(
      field: Key,
      value: TaskListFilters[Key],
    ) => setFilters((current) => ({ ...current, [field]: value })),
    [],
  );

  const retry = () => {
    setError(null);
    setIsLoading(true);
    setIsReferenceLoading(true);
    setRequestVersion((current) => current + 1);
  };

  return (
    <>
      <PageMeta title={`${t("list.title")} | KIM PHỤC SẮC`} description={t("list.metaDescription")} />
      <PageBreadCrumb pageTitle={t("list.title")} />
      <WorkManagementTabs activeTab="tasks" />

      <div className="-mt-4 mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("list.subtitle")}
        </p>
        {(references.actions.can_create || result?.actions.can_create) && (
          <Link
            to="/tasks/new"
            className="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-kps-primary px-5 py-3.5 text-theme-sm font-semibold text-white shadow-theme-xs transition hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none"
          >
            <PlusIcon className="size-5" aria-hidden="true" />
            {t("list.create")}
          </Link>
        )}
      </div>

      <div className="space-y-5">
        {result && <TaskSummary summary={result.summary} />}
        <TaskFilters
          filters={filters}
          references={references}
          isLoading={isReferenceLoading}
          onChange={handleChange}
          onReset={() => setFilters(initialFilters)}
        />

        {error ? (
          <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
            <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
              {t("errors.title")}
            </h2>
            <p className="mt-2 text-theme-sm text-error-600 dark:text-error-400">{error}</p>
            <Button type="button" variant="outline" className="mt-5" onClick={retry}>
              {t("actions.retry")}
            </Button>
          </section>
        ) : isLoading && !result ? (
          <div className="space-y-3" aria-label={t("list.loading")}>
            {[0, 1, 2, 3].map((item) => (
              <div key={item} className="h-18 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800" />
            ))}
          </div>
        ) : result?.data.length ? (
          <>
            <TaskTable tasks={result.data} />
            {result.meta.last_page > 1 && (
              <div className="flex items-center justify-between gap-4 rounded-2xl border border-gray-200 bg-white px-5 py-4 dark:border-gray-800 dark:bg-white/3">
                <p className="text-theme-sm text-gray-500 dark:text-gray-400">
                  {t("list.pagination", {
                    from: result.meta.from ?? 0,
                    to: result.meta.to ?? 0,
                    total: result.meta.total,
                  })}
                </p>
                <div className="flex gap-2">
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={isLoading || currentPage <= 1}
                    onClick={() => {
                      setIsLoading(true);
                      setCurrentPage((page) => page - 1);
                    }}
                  >
                    {t("actions.previous")}
                  </Button>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={isLoading || currentPage >= result.meta.last_page}
                    onClick={() => {
                      setIsLoading(true);
                      setCurrentPage((page) => page + 1);
                    }}
                  >
                    {t("actions.next")}
                  </Button>
                </div>
              </div>
            )}
          </>
        ) : (
          <section className="rounded-2xl border border-gray-200 bg-white px-6 py-12 text-center dark:border-gray-800 dark:bg-white/3">
            <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
              {t("list.emptyTitle")}
            </h2>
            <p className="mt-2 text-theme-sm text-gray-500 dark:text-gray-400">
              {t("list.emptyDescription")}
            </p>
          </section>
        )}
      </div>
    </>
  );
}
