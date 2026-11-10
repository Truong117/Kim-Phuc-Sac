import { useTranslation } from "react-i18next";
import type { FacebookSamplingHealth } from "@/types/facebookAnalytics";
import { observationDate } from "@/utils/facebookAnalytics";

export default function AnalyticsSamplingStatus({
  sampling,
}: {
  sampling?: FacebookSamplingHealth | null;
}) {
  const { t } = useTranslation("common", {
    keyPrefix: "facebookAnalytics.sampling",
  });
  if (!sampling) return null;
  const attention = ["blocked", "paused", "overdue", "unconfirmed"].includes(
    sampling.status,
  );
  return (
    <details
      open={attention}
      className="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800"
    >
      <summary className="cursor-pointer text-gray-700 dark:text-gray-200">
        <span className="font-medium">{t("title")}</span>
        <span
          className={`ms-2 ${attention ? "text-warning-700 dark:text-warning-400" : "text-kps-primary dark:text-brand-300"}`}
        >
          {t(`statuses.${sampling.status}`)}
        </span>
      </summary>
      <div className="mt-3 space-y-3">
        {!sampling.enabled ? (
          <p>{t("disabled")}</p>
        ) : (
          <>
            <div className="grid min-w-0 gap-3 sm:grid-cols-3">
              {(
                [
                  ["lastSample", sampling.last_sampled_at],
                  ["lastCheck", sampling.last_checked_at],
                  ["nextCheck", sampling.next_check_at],
                ] as const
              ).map(([label, value]) => (
                <div key={label} className="min-w-0">
                  <p>{t(label)}</p>
                  <p className="mt-1 font-medium break-words text-gray-700 dark:text-gray-200">
                    {observationDate(value)}
                  </p>
                </div>
              ))}
            </div>
            <p role="status">
              {t("counts", {
                total: sampling.total_posts,
                due: sampling.due_posts,
                overdue: sampling.overdue_posts,
                missing: sampling.never_sampled_posts,
                late: sampling.late_posts,
              })}
            </p>
            <p>{t("cadence")}</p>
            <p>{t("actualTime")}</p>
            {attention && (
              <p className="text-warning-700 dark:text-warning-400">
                {t(`help.${sampling.status}`)}
              </p>
            )}
          </>
        )}
      </div>
    </details>
  );
}
