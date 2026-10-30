import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import WorkManagementTabs from "@/components/tasks/WorkManagementTabs";
import { Modal } from "@/components/ui/modal";
import WeeklyPlanStatusBadge from "@/components/weekly-plans/WeeklyPlanStatusBadge";
import Button from "@/components/ui/button/Button";
import { useModal } from "@/hooks/useModal";
import { PlusIcon } from "@/icons";
import { ApiServiceError } from "@/services/apiClient";
import {
  deleteWeeklyPlan,
  getWeeklyPlanReferences,
  getWeeklyPlans,
} from "@/services/weeklyPlanningService";
import type {
  WeeklyPlanListItem,
  WeeklyPlanListResponse,
  WeeklyPlanReferences,
} from "@/types/weeklyPlanning";
import { formatDateOnly } from "@/utils/dateOnly";
import { activeWeeklyPlanReviewPath } from "@/utils/weeklyPlanReviewer";
import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, useLocation } from "react-router";

export default function WeeklyPlanList() {
  const { t } = useTranslation("common", { keyPrefix: "weeklyPlanning" });
  const location = useLocation();
  const assistantReviewCompleted = Boolean(
    (location.state as { weeklyPlanReviewCompleted?: boolean } | null)
      ?.weeklyPlanReviewCompleted,
  );
  const [result, setResult] = useState<WeeklyPlanListResponse | null>(null);
  const [references, setReferences] = useState<WeeklyPlanReferences | null>(
    null,
  );
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [version, setVersion] = useState(0);
  const [selectedPlan, setSelectedPlan] = useState<WeeklyPlanListItem | null>(
    null,
  );
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const deleteInFlightRef = useRef(false);
  const deleteModal = useModal();

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
        if (
          nextError instanceof DOMException &&
          nextError.name === "AbortError"
        )
          return;
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

  const closeDeleteModal = () => {
    if (deleting) return;
    deleteModal.closeModal();
    setSelectedPlan(null);
    setDeleteError(null);
  };

  const confirmDelete = async () => {
    if (!selectedPlan || deleteInFlightRef.current) return;

    deleteInFlightRef.current = true;
    setDeleting(true);
    setDeleteError(null);
    try {
      await deleteWeeklyPlan(selectedPlan.id);
      deleteModal.closeModal();
      setSelectedPlan(null);
      setLoading(true);
      if (result?.data.length === 1 && page > 1) {
        setPage((current) => current - 1);
      } else {
        setVersion((current) => current + 1);
      }
    } catch (nextError: unknown) {
      setDeleteError(
        nextError instanceof ApiServiceError && nextError.status === 403
          ? t("errors.forbidden")
          : nextError instanceof ApiServiceError && nextError.status === 409
            ? nextError.message
            : t("list.delete.error"),
      );
    } finally {
      deleteInFlightRef.current = false;
      setDeleting(false);
    }
  };

  return (
    <>
      <PageMeta
        title={t("list.metaTitle")}
        description={t("list.metaDescription")}
      />
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

      {assistantReviewCompleted && (
        <p className="mb-5 rounded-xl bg-success-50 px-4 py-3 text-theme-sm font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
          {t("list.assistantReviewCompleted")}
        </p>
      )}

      {error ? (
        <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
          <p className="text-theme-sm text-error-600 dark:text-error-400">
            {error}
          </p>
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
            <div
              key={item}
              className="h-24 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800"
            />
          ))}
        </div>
      ) : result?.data.length ? (
        <div className="space-y-4">
          {result.data.map((plan) => {
            const reviewPath = activeWeeklyPlanReviewPath(plan);

            return (
              <div
                key={plan.id}
                className="rounded-2xl border border-gray-200 bg-white p-5 transition hover:border-kps-primary/40 hover:shadow-theme-sm dark:border-gray-800 dark:bg-white/3"
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
                      <WeeklyPlanStatusBadge
                        status={plan.status}
                        actions={plan.actions}
                      />
                    </div>
                    <p className="mt-2 text-theme-sm text-gray-500 dark:text-gray-400">
                      {t("list.summary", {
                        creator: plan.creator.name,
                        count: plan.entries_count ?? 0,
                      })}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <Link
                      to={reviewPath ?? "/weekly-plans/" + plan.id}
                      className="inline-flex items-center justify-center rounded-lg px-3 py-2 text-theme-sm font-semibold text-kps-primary transition hover:bg-brand-50 focus-visible:ring-3 focus-visible:ring-kps-primary/25 focus-visible:outline-none dark:text-sidebar-selected dark:hover:bg-white/5"
                    >
                      {t(reviewPath ? "list.review" : "list.open")}
                    </Link>
                    {plan.actions.can_delete && (
                      <Button
                        type="button"
                        size="sm"
                        variant="destructive"
                        onClick={() => {
                          setSelectedPlan(plan);
                          setDeleteError(null);
                          deleteModal.openModal();
                        }}
                      >
                        {t("list.delete.action")}
                      </Button>
                    )}
                  </div>
                </div>
              </div>
            );
          })}
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

      <Modal
        isOpen={deleteModal.isOpen}
        onClose={closeDeleteModal}
        showCloseButton={false}
        className="max-w-lg p-6"
        ariaLabelledBy="weekly-plan-delete-title"
      >
        <h2
          id="weekly-plan-delete-title"
          className="text-lg font-semibold text-gray-900 dark:text-white"
        >
          {t("list.delete.title")}
        </h2>
        <p className="mt-3 text-theme-sm text-gray-600 dark:text-gray-300">
          {selectedPlan
            ? t("list.delete.description", {
                start: formatDateOnly(selectedPlan.week_start),
                end: formatDateOnly(selectedPlan.week_end),
                count: selectedPlan.entries_count ?? 0,
              })
            : null}
        </p>
        {deleteError && (
          <p
            role="alert"
            className="mt-4 rounded-lg bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
          >
            {deleteError}
          </p>
        )}
        <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            disabled={deleting}
            onClick={closeDeleteModal}
          >
            {t("list.delete.cancel")}
          </Button>
          <Button
            type="button"
            variant="destructive"
            disabled={deleting}
            onClick={confirmDelete}
          >
            {deleting ? t("list.delete.deleting") : t("list.delete.confirm")}
          </Button>
        </div>
      </Modal>
    </>
  );
}
