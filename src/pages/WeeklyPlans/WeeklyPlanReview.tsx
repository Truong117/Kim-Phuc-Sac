import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import Button from "@/components/ui/button/Button";
import WeeklyPlanReviewWorkspace from "@/components/weekly-plans/WeeklyPlanReviewWorkspace";
import { ApiServiceError } from "@/services/apiClient";
import { getWeeklyPlan } from "@/services/weeklyPlanningService";
import type { WeeklyPlan } from "@/types/weeklyPlanning";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate, useParams } from "react-router";

export default function WeeklyPlanReview() {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.reviewWorkspace",
  });
  const { id } = useParams();
  const navigate = useNavigate();
  const [plan, setPlan] = useState<WeeklyPlan | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [version, setVersion] = useState(0);

  useEffect(() => {
    if (!id) return;
    const controller = new AbortController();
    getWeeklyPlan(id, controller.signal)
      .then((nextPlan) => {
        if (controller.signal.aborted) return;
        setPlan(nextPlan);
        setError(null);
      })
      .catch((nextError: unknown) => {
        if (
          nextError instanceof DOMException &&
          nextError.name === "AbortError"
        ) {
          return;
        }
        setError(
          nextError instanceof ApiServiceError && nextError.status === 403
            ? t("errors.forbidden")
            : nextError instanceof ApiServiceError && nextError.status === 404
              ? t("errors.notFound")
              : t("errors.load"),
        );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [id, t, version]);

  return (
    <>
      <PageMeta title={t("metaTitle")} description={t("metaDescription")} />
      <PageBreadCrumb pageTitle={t("header.title")} />

      {loading && !plan ? (
        <div
          className="h-96 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800"
          aria-label={t("loading")}
        />
      ) : !plan ? (
        <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
          <p className="text-theme-sm text-error-600 dark:text-error-400">
            {error ?? t("errors.load")}
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
            {t("retry")}
          </Button>
        </section>
      ) : (
        <WeeklyPlanReviewWorkspace
          plan={plan}
          onPlanChange={setPlan}
          onAssistantCompleted={() =>
            navigate("/weekly-plans", {
              replace: true,
              state: { weeklyPlanReviewCompleted: true },
            })
          }
        />
      )}
    </>
  );
}
