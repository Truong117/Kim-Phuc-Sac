import { useTranslation } from "react-i18next";
import type {
  FacebookMetric,
  FacebookMilestone,
} from "@/types/facebookAnalytics";
import { analyticsNumber, observationDate } from "@/utils/facebookAnalytics";
export default function AnalyticsMilestoneValue({
  point,
  metric,
}: {
  point: FacebookMilestone;
  metric: FacebookMetric;
}) {
  const { t } = useTranslation("common", {
    keyPrefix: "facebookAnalytics.age",
  });
  return (
    <div className="min-w-0 space-y-1 text-xs">
      <p className="text-base font-semibold text-gray-800 dark:text-gray-100">
        {analyticsNumber(point.metrics[metric])}
      </p>
      <p
        className={
          point.status === "on_time"
            ? "text-success-700 dark:text-success-400"
            : "text-warning-700 dark:text-warning-400"
        }
      >
        {t(`status.${point.status}`)}
      </p>
      <p className="break-words text-gray-500 dark:text-gray-400">
        {t("target", { at: observationDate(point.due_at) })}
      </p>
      {point.sampled_at && (
        <>
          <p className="break-words text-gray-500 dark:text-gray-400">
            {t("actual", { at: observationDate(point.sampled_at) })}
          </p>
          <p className="text-gray-500 dark:text-gray-400">
            {t("delay", {
              minutes: Math.round((point.delay_seconds ?? 0) / 60),
            })}
          </p>
        </>
      )}
    </div>
  );
}
