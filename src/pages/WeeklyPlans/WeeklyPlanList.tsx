import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import WorkManagementTabs from "@/components/tasks/WorkManagementTabs";
import WeeklyPlanStatusBadge from "@/components/weekly-plans/WeeklyPlanStatusBadge";
import Button from "@/components/ui/button/Button";
import { PlusIcon } from "@/icons";
import { ApiServiceError } from "@/services/apiClient";
import {
  getWeeklyPlanReferences,
  getWeeklyPlans,
} from "@/services/weeklyPlanningService";
import type {
  WeeklyPlanListResponse,
  WeeklyPlanReferences,
} from "@/types/weeklyPlanning";
import { formatDateOnly } from "@/utils/dateOnly";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

export default function WeeklyPlanList() {
  const { t } = useTranslation("common", { keyPrefix: "weeklyPlanning" });
  const [result, setResult] = useState<WeeklyPlanListResponse | null>(null);
  const [references, setReferences] = useState<WeeklyPlanReferences | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [version, setVersion] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    Promise.all([
      getWeeklyPlans(page, controller.signal),
      getWeeklyPlanReferences(controller.signal),
    ])
      .then(([plans, nextReferences]) => {
        setResult(plans);
        setReferences(nextReferences);
        setError(null);
      })
      .catch((nextError: unknown) => {
        if (nextError instanceof DOMException && nextError.name === "AbortError") return;
        setError(
          nextError instanceof ApiServiceError && nextError.status === 403
            ? t("errors.forbidden")
            : t("errors.load"),
        );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [page, t, version]);

  return (
    <>
      <PageMeta title={t("list.metaTitle")} description={t("list.metaDescription")} />
      <PageBreadCrumb pageTitle={t("list.title")} />
      <WorkManagementTabs activeTab="weekly-plans" />
      <div className="-mt-4 mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("list.subtitle")}
        </p>
        {references?.actions.can_import && (
          <Link
            to="/weekly-plans/new"
            className="inline-flex items-center justify-center gap-2 rounded-lg bg-kps-primary px-5 py-3 text-theme-sm font-semibold text-white transition hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
          >
            <PlusIcon className="size-5" aria-hidden="true" />
            {t("list.create")}
          </Link>
        )}
      </div>

      {error ? (
        <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
          <p className="text-theme-sm text-error-600 dark:text-error-400">{error}</p>
          <Button
            type="button"
            variant="outline"
            className="mt-4"
            onClick={() => {
              setLoading(true);
              setVersion((current) => current + 1);
            }}
          >
            {t("actions.retry")}
          </Button>
        </section>
      ) : loading && !result ? (
        <div className="space-y-3" aria-label={t("list.loading")}>
          {[0, 1, 2].map((item) => (
            <div key={item} className="h-24 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800" />
          ))}
        </div>
      ) : result?.data.length ? (
        <div className="space-y-4">
          {result.data.map((plan) => (
            <Link
              key={plan.id}
              to={"/weekly-plans/" + plan.id}
              className="block rounded-2xl border border-gray-200 bg-white p-5 transition hover:border-kps-primary/40 hover:shadow-theme-sm focus-visible:ring-3 focus-visible:ring-kps-primary/25 focus-visible:outline-none dark:border-gray-800 dark:bg-white/3"
            >
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="font-semibold text-gray-900 dark:text-white">
                      {t("list.period", {
                        start: formatDateOnly(plan.week_start),
                        end: formatDateOnly(plan.week_end),
                      })}
                    </h2>
                    <WeeklyPlanStatusBadge status={plan.status} />
                  </div>
                  <p className="mt-2 text-theme-sm text-gray-500 dark:text-gray-400">
                    {t("list.summary", {
                      creator: plan.creator.name,
                      count: plan.entries_count ?? 0,
                    })}
                  </p>
                </div>
                <span className="text-theme-sm font-semibold text-kps-primary dark:text-sidebar-selected">
                  {t("list.open")}
                </span>
              </div>
            </Link>
          ))}
          {result.meta.last_page > 1 && (
            <div className="flex justify-end gap-2">
              <Button
                type="button"
                variant="outline"
                disabled={loading || page === 1}
                onClick={() => {
                  setLoading(true);
                  setPage((current) => current - 1);
                }}
              >
                {t("actions.previous")}
              </Button>
              <Button
                type="button"
                variant="outline"
                disabled={loading || page === result.meta.last_page}
                onClick={() => {
                  setLoading(true);
                  setPage((current) => current + 1);
                }}
              >
                {t("actions.next")}
              </Button>
            </div>
          )}
        </div>
      ) : (
        <section className="rounded-2xl border border-gray-200 bg-white px-6 py-12 text-center dark:border-gray-800 dark:bg-white/3">
          <h2 className="font-semibold text-gray-900 dark:text-white">
            {t("list.emptyTitle")}
          </h2>
          <p className="mt-2 text-theme-sm text-gray-500 dark:text-gray-400">
            {t("list.emptyDescription")}
          </p>
        </section>
      )}
    </>
  );
}
