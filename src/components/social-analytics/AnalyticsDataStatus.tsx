import { useId, useState } from "react";
import { useTranslation } from "react-i18next";
import { InfoCircleIcon } from "@/icons";
import AnalyticsSamplingStatus from "./AnalyticsSamplingStatus";
import type { FacebookAnalyticsData } from "@/types/facebookAnalytics";
import { formatDateOnly } from "@/utils/dateOnly";

export default function AnalyticsDataStatus({
  data,
  waiting,
}: {
  data: FacebookAnalyticsData;
  waiting: boolean;
}) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const id = useId();
  const attention =
    waiting ||
    !data.sync.catalogue_complete ||
    !!data.sync.error_code ||
    data.sync.interrupted ||
    ["blocked", "paused", "overdue", "unconfirmed"].includes(
      data.sampling?.status ?? "",
    );
  const [expanded, setExpanded] = useState<boolean | null>(() =>
    attention ? true : null,
  );
  const open = expanded ?? attention;
  return (
    <section className="min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
      <button
        type="button"
        aria-expanded={open}
        aria-controls={id}
        onClick={() => setExpanded(!open)}
        className="flex w-full flex-wrap items-center justify-between gap-3 bg-kps-primary/5 p-4 text-start text-xs text-gray-700 dark:bg-kps-primary/10 dark:text-gray-200"
      >
        <span className="flex min-w-0 items-center gap-2">
          <InfoCircleIcon className="size-4 shrink-0 text-kps-primary" />
          <span className="break-words">
            {t("review.statusSummary", {
              name: data.page_name,
              count: data.summary.observed_posts,
            })}
          </span>
        </span>
        <span className="text-kps-primary underline dark:text-brand-300">
          {t(open ? "review.hideStatus" : "review.showStatus")}
        </span>
      </button>
      <div
        id={id}
        hidden={!open}
        className="space-y-2 p-4 text-xs leading-relaxed text-gray-500 dark:text-gray-400"
      >
        <p>
          {t("coverage", {
            published: data.summary.published,
            observed: data.summary.observed_posts,
          })}
        </p>
        {!data.sync.catalogue_complete && (
          <p className="text-warning-700 dark:text-warning-400">
            {t("cataloguePending")}
          </p>
        )}
        {data.sync.error_code && (
          <p className="text-error-600 dark:text-error-400">
            {t("syncFailed")}
          </p>
        )}
        {data.sync.interrupted && (
          <p className="text-warning-700 dark:text-warning-400">
            {t("interrupted")}
          </p>
        )}
        <p>{t("dataPolicy")}</p>
        <AnalyticsSamplingStatus sampling={data.sampling} />
        {data.sync.progress && (
          <details
            open={!!waiting}
            className="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800"
          >
            <summary className="cursor-pointer font-medium text-gray-700 dark:text-gray-200">
              <span>{t(`syncPhases.${data.sync.progress.phase}`)}</span>
              <span className="ms-2 text-kps-primary dark:text-brand-300">
                {data.sync.progress.ready_posts}/
                {data.sync.progress.catalogue_posts}
              </span>
            </summary>
            <div className="mt-2 space-y-2">
              <p>
                {t("syncScope", {
                  from: formatDateOnly(data.sync.progress.from_date),
                  to: formatDateOnly(data.sync.progress.to_date),
                })}
              </p>
              <p role="status">
                {t("syncProgress", {
                  ready: data.sync.progress.ready_posts,
                  total: data.sync.progress.catalogue_posts,
                  pending: data.sync.progress.pending_posts,
                  batches: data.sync.progress.batches_completed,
                })}
              </p>
              {data.sync.catalogue_complete &&
                data.sync.progress.catalogue_posts > 0 && (
                  <div
                    role="progressbar"
                    aria-label={t("syncProgressLabel")}
                    aria-valuemin={0}
                    aria-valuemax={data.sync.progress.catalogue_posts}
                    aria-valuenow={data.sync.progress.ready_posts}
                    className="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"
                  >
                    <div
                      className="h-full rounded-full bg-kps-primary"
                      style={{
                        width: `${Math.min(100, (data.sync.progress.ready_posts / data.sync.progress.catalogue_posts) * 100)}%`,
                      }}
                    />
                  </div>
                )}
            </div>
          </details>
        )}
      </div>
    </section>
  );
}
