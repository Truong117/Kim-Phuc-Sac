import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import {
  dateOnlyInTimeZone,
  dateOnlyFromParts,
  monthFromDateOnly,
  shiftCalendarMonth,
  formatDateOnly,
} from "@/utils/dateOnly";
import { analyticsNumber, observationDate } from "@/utils/facebookAnalytics";
import type {
  FacebookAnalyticsData,
  FacebookMetric,
} from "@/types/facebookAnalytics";
import { useFacebookMonthlyReport } from "@/hooks/useFacebookMonthlyReport";
import AnalyticsReviewFindings from "./AnalyticsReviewFindings";
import AnalyticsMonthlyCloseForm from "./AnalyticsMonthlyCloseForm";

export default function AnalyticsMonthlyReview({
  data,
  onPost,
  onDirtyChange,
  onLeaveReview,
}: {
  data: FacebookAnalyticsData;
  onPost: (id: number) => void;
  onDirtyChange: (dirty: boolean) => void;
  onLeaveReview: () => boolean;
}) {
  const [month, setMonth] = useState(() => {
    const m = shiftCalendarMonth(monthFromDateOnly(data.to_date), -1);
    return dateOnlyFromParts(m.year, m.month, 1).slice(0, 7);
  });
  const [live, setLive] = useState(false);
  const today = dateOnlyInTimeZone();
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  function select(date: string) {
    const next = date.slice(0, 7);
    if (next === month || !onLeaveReview()) return;
    setMonth(next);
    setLive(false);
  }
  return (
    <div className="space-y-4">
      <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
        <h2 className="font-semibold text-gray-800 dark:text-gray-100">
          {t("monthly.title")}
        </h2>
        <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
          {t("monthly.scope")}
        </p>
        <div className="flex flex-wrap items-center gap-2">
          <div className="w-full max-w-xs">
            <KpsDatePicker
              value={month + "-01"}
              ariaLabel={t("monthly.month")}
              placeholder={t("monthly.month")}
              onChange={select}
              minDate="2020-01-01"
              maxDate={today}
            />
          </div>
          <Button
            size="sm"
            variant="outline"
            disabled={month <= "2020-01"}
            onClick={() => {
              const m = shiftCalendarMonth(
                monthFromDateOnly(month + "-01"),
                -1,
              );
              select(dateOnlyFromParts(m.year, m.month, 1));
            }}
          >
            {t("monthly.previous")}
          </Button>
          <Button
            size="sm"
            variant="outline"
            disabled={month >= today.slice(0, 7)}
            onClick={() => {
              const m = shiftCalendarMonth(monthFromDateOnly(month + "-01"), 1);
              select(dateOnlyFromParts(m.year, m.month, 1));
            }}
          >
            {t("monthly.next")}
          </Button>
        </div>
        <p className="text-xs text-gray-500 dark:text-gray-400">
          {t("monthly.selectorNote", { month })}
        </p>
      </section>
      <MonthlyResult
        key={`${data.entry_id}:${month}:${live}`}
        id={data.entry_id}
        month={month}
        live={live}
        onPost={onPost}
        onDirtyChange={onDirtyChange}
        onView={(next) => {
          if (onLeaveReview()) setLive(next);
        }}
      />
    </div>
  );
}
function MonthlyResult({
  id,
  month,
  live,
  onPost,
  onDirtyChange,
  onView,
}: {
  id: number;
  month: string;
  live: boolean;
  onPost: (id: number) => void;
  onDirtyChange: (dirty: boolean) => void;
  onView: (live: boolean) => void;
}) {
  const api = useFacebookMonthlyReport(id, month, live),
    report = api.report;
  const [metric, setMetric] = useState<FacebookMetric>("likes");
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  useEffect(() => {
    onDirtyChange(api.dirty);
    return () => onDirtyChange(false);
  }, [api.dirty, onDirtyChange]);
  const review = report?.reviews[metric];
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <Button
          size="sm"
          variant="outline"
          disabled={api.loading || api.saving}
          onClick={api.refresh}
        >
          {t("monthly.reload")}
        </Button>
        {report?.existing_snapshot && (
          <Button
            size="sm"
            variant="outline"
            disabled={api.saving}
            onClick={() => onView(!live)}
          >
            {t(live ? "monthly.viewSaved" : "monthly.viewLive")}
          </Button>
        )}
      </div>
      {api.failed && (
        <p role="alert" className="text-xs text-error-600 dark:text-error-400">
          {t("monthly.loadFailed")}
        </p>
      )}
      {!report || !review ? (
        <p role="status" className="text-sm text-gray-500 dark:text-gray-400">
          {t(api.failed ? "monthly.loadFailed" : "loading")}
        </p>
      ) : (
        <>
          <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <h3 className="font-semibold text-gray-800 dark:text-gray-100">
              {report.page_name} · {month}
            </h3>
            <p
              role="status"
              className="text-sm font-medium text-kps-primary dark:text-brand-300"
            >
              {t(
                report.status === "closed"
                  ? "monthly.closed"
                  : "monthly.preview",
              )}
            </p>
            <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
              {report.status === "closed"
                ? t("monthly.frozen", {
                    at: observationDate(report.closed_at),
                    name: report.closed_by_name,
                  })
                : t("monthly.available", {
                    at: observationDate(report.close_available_at),
                  })}
            </p>
            {live && report.existing_snapshot && (
              <p className="text-xs text-warning-700 dark:text-warning-400">
                {t("monthly.liveNote")}
              </p>
            )}
            {report.limited && (
              <p className="text-xs leading-relaxed text-warning-700 dark:text-warning-400">
                {t("monthly.limited")}
              </p>
            )}
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t("monthly.sourceAt", { at: observationDate(report.as_of) })}
            </p>
          </section>
          <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <h3 className="font-semibold text-gray-800 dark:text-gray-100">
              {t("monthly.activity")}
            </h3>
            <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
              {t("monthly.activityNote")}
            </p>
            <div className="grid gap-3 sm:grid-cols-3">
              {(["likes", "comments", "media_views"] as const).map((m) => {
                const a = report.activity.metrics[m];
                return (
                  <article
                    key={m}
                    className="min-w-0 rounded-lg bg-gray-50 p-3 dark:bg-gray-900"
                  >
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                      {t(`metrics.${m}`)}
                    </p>
                    <p className="my-2 text-xl font-semibold text-gray-800 dark:text-gray-100">
                      {analyticsNumber(a.value)}
                    </p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                      {t("monthly.activityCount", {
                        posts: a.posts,
                        pairs: a.pairs,
                      })}
                    </p>
                    <p className="mt-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                      {observationDate(a.observed_from)} →{" "}
                      {observationDate(a.observed_to)}
                    </p>
                    {a.max_gap_seconds > 86400 && (
                      <p className="mt-1 text-xs text-warning-700 dark:text-warning-400">
                        {t("monthly.longGap")}
                      </p>
                    )}
                  </article>
                );
              })}
            </div>
          </section>
          <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <h3 className="font-semibold text-gray-800 dark:text-gray-100">
              {t("monthly.cohort")}
            </h3>
            <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
              {t("monthly.cohortNote", {
                from: formatDateOnly(review.from_date),
                to: formatDateOnly(review.to_date),
                previousFrom: formatDateOnly(review.previous_from),
                previousTo: formatDateOnly(review.previous_to),
              })}
            </p>
            <p className="text-sm text-gray-800 dark:text-gray-100">
              {t("monthly.counts", {
                total: review.quality.total,
                valid: review.quality.statuses.on_time,
                missing: review.quality.statuses.missing,
                late: review.quality.statuses.late,
                waiting:
                  review.quality.statuses.waiting + review.quality.statuses.due,
              })}
            </p>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t("period.policy")}
            </p>
            <label className="block max-w-xs space-y-1 text-xs text-gray-500 dark:text-gray-400">
              <span>{t("metric")}</span>
              <select
                value={metric}
                onChange={(e) => setMetric(e.target.value as FacebookMetric)}
                className="h-11 w-full min-w-0 rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-900"
              >
                {(["likes", "comments", "media_views"] as const).map((m) => (
                  <option key={m} value={m}>
                    {t(`age.metricNames.${m}`)}
                  </option>
                ))}
              </select>
            </label>
          </section>
          <AnalyticsReviewFindings
            result={review}
            metric={metric}
            onPost={report.status === "closed" ? undefined : onPost}
          />
          {report.status === "closed" ? (
            <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
              <h3 className="font-semibold text-gray-800 dark:text-gray-100">
                {t("monthly.closedActions")}
              </h3>
              <p className="text-sm break-words whitespace-pre-wrap text-gray-600 dark:text-gray-300">
                {report.notes || t("monthly.noNotes")}
              </p>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {t("period.owner", {
                  name: report.reviews.likes.owner?.name ?? t("period.noOwner"),
                })}
              </p>
              {report.actions.map((a) => (
                <article
                  key={a.id}
                  className="space-y-1 rounded-lg bg-gray-50 p-3 dark:bg-gray-900"
                >
                  <h4 className="text-sm font-medium break-words text-gray-800 dark:text-gray-100">
                    {a.title}
                  </h4>
                  <p className="text-xs break-words whitespace-pre-wrap text-gray-500 dark:text-gray-400">
                    {a.expected_result}
                  </p>
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    {t("monthly.actionDates", {
                      due: formatDateOnly(a.due_date),
                      review: formatDateOnly(a.review_date),
                    })}{" "}
                    ·{" "}
                    {t(
                      a.metric
                        ? `age.metricNames.${a.metric}`
                        : "monthly.operational",
                    )}
                  </p>
                </article>
              ))}
              {!report.actions.length && (
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  {t("monthly.noActions")}
                </p>
              )}
            </section>
          ) : (
            <AnalyticsMonthlyCloseForm api={api} />
          )}
        </>
      )}
    </div>
  );
}
