import { useRef } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import { dateOnlyInTimeZone, addDaysToDateOnly } from "@/utils/dateOnly";
import type { FacebookReviewAction } from "@/types/facebookAnalytics";
import type { useFacebookMonthlyReport } from "@/hooks/useFacebookMonthlyReport";

export default function AnalyticsMonthlyCloseForm({
  api,
}: {
  api: ReturnType<typeof useFacebookMonthlyReport>;
}) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const form = useRef<HTMLFormElement>(null),
    report = api.report;
  if (!report || report.status === "closed" || report.existing_snapshot)
    return null;
  const field =
    "w-full min-w-0 rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100";
  const today = dateOnlyInTimeZone();
  const change = (i: number, values: Partial<FacebookReviewAction>) =>
    api.setActions((old) =>
      old.map((a, n) => (i === n ? { ...a, ...values } : a)),
    );
  const error = (key: string) => api.errors[key]?.join(" ");
  return (
    <form
      ref={form}
      className="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3"
      onSubmit={(e) => {
        e.preventDefault();
        if (!window.confirm(t("monthly.confirm"))) return;
        void api
          .close()
          .then(() =>
            requestAnimationFrame(() =>
              form.current
                ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                ?.focus(),
            ),
          );
      }}
    >
      <h3 className="font-semibold text-gray-800 dark:text-gray-100">
        {t("monthly.closeTitle")}
      </h3>
      <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
        {t("monthly.closeNote")}
      </p>
      <fieldset disabled={api.saving} className="min-w-0 space-y-4">
        <label className="block space-y-1 text-xs text-gray-500 dark:text-gray-400">
          <span>{t("monthly.notes")}</span>
          <textarea
            rows={3}
            value={api.notes}
            maxLength={2000}
            onChange={(e) => api.setNotes(e.target.value)}
            className={field}
            aria-invalid={!!error("notes")}
          />
        </label>
        <p className="text-xs text-gray-500 dark:text-gray-400">
          {t("period.owner", {
            name: report.reviews.likes.owner?.name ?? t("period.noOwner"),
          })}
        </p>
        {api.actions.map((a, i) => (
          <fieldset
            key={a.id}
            className="min-w-0 space-y-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700"
          >
            <legend className="px-1 text-sm text-gray-800 dark:text-gray-100">
              {t("period.actionNumber", { number: i + 1 })}
            </legend>
            <div className="grid min-w-0 gap-3 sm:grid-cols-2">
              {(["title", "expected_result"] as const).map((key) => (
                <label
                  key={key}
                  className="min-w-0 space-y-1 text-xs text-gray-500 dark:text-gray-400"
                >
                  <span>
                    {t(
                      key === "title"
                        ? "period.actionTitle"
                        : "period.expected_result",
                    )}
                  </span>
                  <textarea
                    required
                    rows={2}
                    maxLength={key === "title" ? 160 : 600}
                    value={a[key]}
                    onChange={(e) => change(i, { [key]: e.target.value })}
                    className={field}
                    aria-invalid={!!error(`actions.${i}.${key}`)}
                  />
                </label>
              ))}
              <label className="min-w-0 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span>{t("period.measure")}</span>
                <select
                  className={field}
                  value={a.metric ?? ""}
                  onChange={(e) => {
                    const metric = (e.target.value ||
                      null) as FacebookReviewAction["metric"];
                    change(i, {
                      metric,
                      review_date: addDaysToDateOnly(
                        a.due_date,
                        metric ? 7 : 0,
                      ),
                    });
                  }}
                >
                  <option value="">{t("monthly.operational")}</option>
                  {(["likes", "comments", "media_views"] as const).map((m) => (
                    <option key={m} value={m}>
                      {t(`age.metricNames.${m}`)}
                    </option>
                  ))}
                </select>
              </label>
              {(["due_date", "review_date"] as const).map((key) => (
                <div key={key} className="min-w-0">
                  <KpsDatePicker
                    ariaLabel={t(
                      key === "due_date"
                        ? "period.due_date"
                        : "period.review_date",
                    )}
                    placeholder={t(
                      key === "due_date"
                        ? "period.due_date"
                        : "period.review_date",
                    )}
                    value={a[key]}
                    onChange={(date) =>
                      change(
                        i,
                        key === "due_date"
                          ? {
                              due_date: date,
                              review_date: addDaysToDateOnly(
                                date,
                                a.metric ? 7 : 0,
                              ),
                            }
                          : { review_date: date },
                      )
                    }
                    minDate={
                      key === "due_date"
                        ? today
                        : addDaysToDateOnly(a.due_date, a.metric ? 7 : 0)
                    }
                    required
                    error={!!error(`actions.${i}.${key}`)}
                  />
                </div>
              ))}
            </div>
            <Button
              size="sm"
              variant="outline"
              onClick={() =>
                api.setActions((old) => old.filter((_, n) => n !== i))
              }
            >
              {t("monthly.removeAction")}
            </Button>
          </fieldset>
        ))}
        <Button
          size="sm"
          variant="outline"
          disabled={api.actions.length >= 3 || !report.reviews.likes.owner}
          onClick={() =>
            api.setActions((old) => [
              ...old,
              {
                id: crypto.randomUUID(),
                title: "",
                expected_result: "",
                metric: "likes",
                due_date: today,
                review_date: addDaysToDateOnly(today, 7),
                status: "planned",
                result_note: "",
              },
            ])
          }
        >
          {t("period.addAction")}
        </Button>
        <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
          {t("monthly.actionsNote")}
        </p>
        {report.limited && (
          <label className="flex items-start gap-2 text-xs leading-relaxed text-warning-800 dark:text-warning-400">
            <input
              type="checkbox"
              required
              checked={api.ack}
              onChange={(e) => api.setAck(e.target.checked)}
              className="mt-1 shrink-0 accent-kps-primary"
              aria-invalid={!!error("acknowledge_limits")}
            />
            <span>{t("monthly.ack")}</span>
          </label>
        )}
        {Object.entries(api.errors).map(([key, value]) => (
          <p
            key={key}
            role="alert"
            className="text-xs text-error-600 dark:text-error-400"
          >
            {value.join(" ")}
          </p>
        ))}
        {api.error && (
          <p
            role="alert"
            className="text-xs leading-relaxed text-error-600 dark:text-error-400"
          >
            {t(`monthly.errors.${api.error}`)}
          </p>
        )}
        <Button
          size="sm"
          disabled={
            !report.can_close ||
            api.loading ||
            api.failed ||
            api.saving ||
            api.error === "conflict" ||
            api.error === "uncertain"
          }
          type="submit"
        >
          {t(api.saving ? "monthly.closing" : "monthly.close")}
        </Button>
      </fieldset>
    </form>
  );
}
