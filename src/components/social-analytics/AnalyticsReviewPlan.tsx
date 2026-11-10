import { useId, useRef } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import type {
  FacebookPeriodReviewData,
  FacebookReviewAction,
} from "@/types/facebookAnalytics";
import type { useFacebookPeriodReview } from "@/hooks/useFacebookPeriodReview";
import { addDaysToDateOnly, dateOnlyInTimeZone } from "@/utils/dateOnly";
import { observationDate } from "@/utils/facebookAnalytics";

type ReviewApi = ReturnType<typeof useFacebookPeriodReview>;
export default function AnalyticsReviewPlan({
  api,
  data,
}: {
  api: ReviewApi;
  data: FacebookPeriodReviewData;
}) {
  const { t } = useTranslation("common", {
    keyPrefix: "facebookAnalytics.period",
  });
  const prefix = useId(),
    form = useRef<HTMLFormElement>(null);
  const draft = api.draft;
  if (!draft) return null;
  const fieldClass =
    "w-full min-w-0 rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100";
  const change = (index: number, values: Partial<FacebookReviewAction>) =>
    api.edit((old) => ({
      ...old,
      actions: old.actions.map((a, i) =>
        i === index ? { ...a, ...values } : a,
      ),
    }));
  const error = (key: string) => api.errors[key]?.join(" ");
  return (
    <section className="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
      <h2 className="font-semibold text-gray-800 dark:text-gray-100">
        {t("planTitle")}
      </h2>
      <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
        {t("planNote")}
      </p>
      <p className="text-xs text-gray-500 dark:text-gray-400">
        {t("owner", { name: data.owner?.name ?? t("noOwner") })}
      </p>
      {!data.owner && (
        <p
          role="status"
          className="text-xs text-warning-700 dark:text-warning-400"
        >
          {t("ownerNeeded")}
        </p>
      )}
      {(api.conflict || api.saveState === "conflict") && (
        <p
          role="alert"
          className="rounded-lg bg-warning-50 p-3 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-400"
        >
          {t("conflict")}
        </p>
      )}
      {data.plan.source_changed && (
        <p
          role="status"
          className="text-xs text-warning-700 dark:text-warning-400"
        >
          {t("sourceChanged")}
        </p>
      )}
      <form
        ref={form}
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          void api
            .save()
            .then(() =>
              requestAnimationFrame(() =>
                form.current
                  ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                  ?.focus(),
              ),
            );
        }}
      >
        <label className="block max-w-xs space-y-1 text-xs text-gray-500 dark:text-gray-400">
          <span>{t("expectedPosts")}</span>
          <input
            aria-invalid={!!error("expected_posts")}
            type="number"
            min="1"
            max="1000"
            step="1"
            value={draft.expected_posts}
            disabled={api.saving}
            onChange={(e) =>
              api.edit((old) => ({ ...old, expected_posts: e.target.value }))
            }
            className={fieldClass}
          />
          {error("expected_posts") && (
            <span className="text-error-600 dark:text-error-400">
              {error("expected_posts")}
            </span>
          )}
        </label>
        {draft.actions.map((action, i) => (
          <fieldset
            key={action.id}
            disabled={api.saving}
            className="min-w-0 space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700"
          >
            <legend className="px-1 text-sm font-medium text-gray-800 dark:text-gray-100">
              {t("actionNumber", { number: i + 1 })}
            </legend>
            <div className="grid min-w-0 gap-3 sm:grid-cols-2">
              {(["title", "expected_result"] as const).map((key) => (
                <label
                  key={key}
                  className="block min-w-0 space-y-1 text-xs text-gray-500 dark:text-gray-400"
                >
                  <span>{t(key === "title" ? "actionTitle" : key)}</span>
                  <textarea
                    required
                    value={action[key]}
                    rows={key === "title" ? 2 : 3}
                    maxLength={key === "title" ? 160 : 600}
                    aria-invalid={!!error(`actions.${i}.${key}`)}
                    onChange={(e) => change(i, { [key]: e.target.value })}
                    className={fieldClass}
                  />
                  {error(`actions.${i}.${key}`) && (
                    <span className="text-error-600 dark:text-error-400">
                      {error(`actions.${i}.${key}`)}
                    </span>
                  )}
                </label>
              ))}
              <label className="space-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span>{t("measure")}</span>
                <select
                  value={action.metric ?? ""}
                  onChange={(e) => {
                    const metric = (e.target.value ||
                      null) as FacebookReviewAction["metric"];
                    change(i, {
                      metric,
                      review_date:
                        action.review_date <
                        addDaysToDateOnly(action.due_date, metric ? 7 : 0)
                          ? addDaysToDateOnly(action.due_date, metric ? 7 : 0)
                          : action.review_date,
                    });
                  }}
                  className={fieldClass}
                >
                  <option value="">{t("operational")}</option>
                  {["likes", "comments", "media_views"].map((k) => (
                    <option key={k} value={k}>
                      {t(`metricNames.${k}`)}
                    </option>
                  ))}
                </select>
              </label>
              <label className="space-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span>{t("actionStatus")}</span>
                <select
                  value={action.status}
                  onChange={(e) =>
                    change(i, {
                      status: e.target.value as FacebookReviewAction["status"],
                    })
                  }
                  className={fieldClass}
                >
                  {["planned", "doing", "done"].map((k) => (
                    <option key={k} value={k}>
                      {t(`actionStatuses.${k}`)}
                    </option>
                  ))}
                </select>
              </label>
              {(["due_date", "review_date"] as const).map((key) => (
                <div
                  key={key}
                  className="min-w-0 space-y-1 text-xs text-gray-500 dark:text-gray-400"
                >
                  <label htmlFor={`${prefix}-${action.id}-${key}`}>
                    {t(key)}
                  </label>
                  <KpsDatePicker
                    id={`${prefix}-${action.id}-${key}`}
                    value={action[key]}
                    required
                    disabled={api.saving}
                    minDate={
                      key === "review_date"
                        ? addDaysToDateOnly(
                            action.due_date,
                            action.metric ? 7 : 0,
                          )
                        : undefined
                    }
                    onChange={(value) =>
                      change(i, {
                        [key]: value,
                        ...(key === "due_date" &&
                        action.review_date <
                          addDaysToDateOnly(value, action.metric ? 7 : 0)
                          ? {
                              review_date: addDaysToDateOnly(
                                value,
                                action.metric ? 7 : 0,
                              ),
                            }
                          : {}),
                      })
                    }
                    ariaLabel={t(key)}
                    placeholder={t(key)}
                    dialogLabel={t(key)}
                    error={!!error(`actions.${i}.${key}`)}
                  />
                  {error(`actions.${i}.${key}`) && (
                    <p className="text-error-600 dark:text-error-400">
                      {error(`actions.${i}.${key}`)}
                    </p>
                  )}
                </div>
              ))}
            </div>
            <label className="block space-y-1 text-xs text-gray-500 dark:text-gray-400">
              <span>{t("resultNote")}</span>
              <textarea
                rows={2}
                maxLength={1000}
                value={action.result_note}
                onChange={(e) => change(i, { result_note: e.target.value })}
                className={fieldClass}
              />
            </label>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() =>
                api.edit((old) => ({
                  ...old,
                  actions: old.actions.filter((a) => a.id !== action.id),
                }))
              }
            >
              {t("remove")}
            </Button>
          </fieldset>
        ))}
        {error("actions") && (
          <p
            role="alert"
            className="text-xs text-error-600 dark:text-error-400"
          >
            {error("actions")}
          </p>
        )}
        <div className="flex flex-wrap gap-3">
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={api.saving || draft.actions.length >= 3 || !data.owner}
            onClick={() => {
              const due = addDaysToDateOnly(dateOnlyInTimeZone(), 1);
              api.edit((old) => ({
                ...old,
                actions: [
                  ...old.actions,
                  {
                    id: crypto.randomUUID(),
                    title: "",
                    expected_result: "",
                    metric: "likes",
                    due_date: due,
                    review_date: addDaysToDateOnly(due, 7),
                    status: "planned",
                    result_note: "",
                  },
                ],
              }));
            }}
          >
            {t("addAction")}
          </Button>
          <Button
            type="submit"
            size="sm"
            disabled={
              api.saving ||
              (!api.dirty && !data.plan.source_changed) ||
              api.conflict
            }
          >
            {t(api.saving ? "saving" : "save")}
          </Button>
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={api.loading || api.saving}
            onClick={api.refresh}
          >
            {t("refreshSource")}
          </Button>
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={api.saving || !api.dirty}
            onClick={() => {
              if (window.confirm(t("discardConfirm"))) api.adoptServer();
            }}
          >
            {t("useServer")}
          </Button>
        </div>
      </form>
      {api.saveState && api.saveState !== "conflict" && (
        <p
          role={api.saveState === "failed" ? "alert" : "status"}
          className={
            api.saveState === "failed"
              ? "text-xs text-error-600 dark:text-error-400"
              : "text-xs text-success-700 dark:text-success-400"
          }
        >
          {t(api.saveState === "saved" ? "saved" : "saveFailed")}
        </p>
      )}
      <p className="text-xs text-gray-500 dark:text-gray-400">
        {api.dirty
          ? t("unsaved")
          : t("lastSaved", { at: observationDate(data.plan.updated_at) })}
      </p>
    </section>
  );
}
