import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import type { useCrmReportDraft } from "@/hooks/useCrmReportDraft";

export default function CrmReportDraftPanel({
  crm,
  disabled,
  onViewReport,
}: {
  crm: ReturnType<typeof useCrmReportDraft>;
  disabled: boolean;
  onViewReport: () => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "crm.report" });
  if (!crm.hasScope) return null;

  return (
    <section id="crm-report-sources" tabIndex={-1} aria-label={t("title")}>
      <ComponentCard title={t("title")} desc={t("hint")}>
        <div className="space-y-3">
          {crm.error && (
            <p
              role="alert"
              className="text-sm text-error-600 dark:text-error-300"
            >
              {t(crm.selection ? "loadErrorWithSelection" : "loadError")}
            </p>
          )}
          {!crm.loading && !crm.error && !crm.activities.length && (
            <p className="text-sm text-gray-600 dark:text-gray-400">
              {t("empty")}
            </p>
          )}
          {!!crm.activities.length && (
            <ul className="max-h-80 divide-y divide-gray-200 overflow-y-auto dark:divide-gray-700">
              {crm.activities.map((activity) => (
                <li key={activity.id}>
                  <label className="flex cursor-pointer items-start gap-3 py-3 text-sm">
                    <input
                      type="checkbox"
                      disabled={disabled || crm.loading}
                      checked={!crm.excluded.includes(activity.id)}
                      onChange={() => crm.toggle(activity.id)}
                      className="mt-1 size-4 shrink-0 accent-kps-primary"
                    />
                    <span className="min-w-0">
                      <span className="block font-semibold text-gray-900 dark:text-white">
                        {activity.title} · {activity.customer_name}
                      </span>
                      <span className="block text-xs text-gray-600 dark:text-gray-400">
                        {t("placement." + activity.kind)} ·{" "}
                        {activity.customer_code}
                        {activity.due_on && " · " + activity.due_on}
                      </span>
                      {!!activity.services.length && (
                        <span className="block text-xs text-kps-primary dark:text-kps-primary-hover">
                          {activity.services
                            .map(({ name }) => name)
                            .join(" · ")}
                        </span>
                      )}
                      {activity.result && (
                        <span className="block break-words text-gray-700 dark:text-gray-300">
                          {activity.result}
                        </span>
                      )}
                    </span>
                  </label>
                </li>
              ))}
            </ul>
          )}
          {!crm.error && crm.hasConsultation && (
            <div>
              <Label htmlFor="crm-report-task">{t("careTask")}</Label>
              <Select
                id="crm-report-task"
                value={String(crm.taskId)}
                onChange={(value) => crm.selectTask(Number(value))}
                placeholder={t("reportOnly")}
                disabled={disabled || crm.loading}
                options={[
                  { value: "0", label: t("reportOnly") },
                  ...crm.tasks.map(({ id, title }) => ({
                    value: String(id),
                    label: title,
                  })),
                ]}
              />
              <p className="mt-1 text-xs text-gray-600 dark:text-gray-400">
                {t(crm.tasks.length ? "countHint" : "noCareTask")}
              </p>
            </div>
          )}
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              size="sm"
              onClick={onViewReport}
              className="!bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-kps-primary/35"
              disabled={disabled || !crm.selection}
            >
              {t("viewReport")}
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={crm.refresh}
              disabled={disabled || crm.loading}
            >
              {t(crm.loading ? "loading" : "refresh")}
            </Button>
          </div>
          {(crm.loading || crm.refreshed) && (
            <p
              role="status"
              className="text-sm text-gray-600 dark:text-gray-400"
            >
              {crm.loading
                ? t(crm.saveWaiting ? "loadingBeforeSave" : "loading")
                : t("refreshed", { count: crm.activities.length })}
            </p>
          )}
        </div>
      </ComponentCard>
    </section>
  );
}
