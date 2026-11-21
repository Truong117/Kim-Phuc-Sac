import { useTranslation } from "react-i18next";
import type { CrmReportPreviewRow } from "@/utils/crmReportPreview";

export default function CrmReportPreview({
  rows,
}: {
  rows: CrmReportPreviewRow[];
}) {
  const { t } = useTranslation("common", { keyPrefix: "crm.report" });
  if (!rows.length) return null;

  return (
    <div
      data-crm-report-preview
      role="group"
      tabIndex={-1}
      aria-label={t("previewTitle")}
      className="space-y-3 rounded-xl border border-kps-primary/25 bg-white p-4 focus-visible:ring-2 focus-visible:ring-kps-primary dark:bg-gray-900"
    >
      <p className="text-xs font-medium text-kps-primary dark:text-brand-200">
        {t("previewTitle")}
      </p>
      {rows.map((row) =>
        row.kind === "care" ? (
          <article key={`task-${row.task.id}`} className="space-y-2 text-sm">
            <h3 className="font-semibold break-words text-gray-900 dark:text-white">
              {row.task.title}
            </h3>
            {row.existing && (
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {t("appendToTask")}
              </p>
            )}
            <ul className="divide-y divide-gray-200 dark:divide-gray-700">
              {row.activities.map((activity) => (
                <li key={activity.id} className="space-y-1 py-2">
                  <p className="font-medium break-words text-gray-900 dark:text-gray-100">
                    {activity.customer_code} · {activity.customer_name}
                  </p>
                  <p className="break-words text-kps-primary dark:text-brand-200">
                    {activity.services.map(({ name }) => name).join(" · ")}
                  </p>
                  <p className="break-words whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                    {activity.result}
                  </p>
                </li>
              ))}
            </ul>
          </article>
        ) : (
          <article key={row.activity.id} className="space-y-1 text-sm">
            <h3 className="font-semibold break-words text-gray-900 dark:text-white">
              {row.activity.title} — {row.activity.customer_code}
            </h3>
            <p className="text-xs break-words text-gray-500 dark:text-gray-400">
              {row.activity.customer_name}
            </p>
            {row.activity.result && (
              <p className="break-words whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                {row.activity.result}
              </p>
            )}
          </article>
        ),
      )}
    </div>
  );
}

export function CrmPlanPreview({ lines }: { lines: string[] }) {
  const { t } = useTranslation("common", { keyPrefix: "crm.report" });
  if (!lines.length) return null;
  return (
    <div
      data-crm-report-preview
      role="group"
      tabIndex={-1}
      aria-label={t("previewTitle")}
      className="mt-3 rounded-xl border border-kps-primary/25 p-4 text-sm focus-visible:ring-2 focus-visible:ring-kps-primary dark:border-kps-primary/40"
    >
      <p className="text-xs font-medium text-kps-primary dark:text-brand-200">
        {t("previewTitle")}
      </p>
      <ul className="mt-2 list-disc space-y-1 ps-5 text-gray-700 dark:text-gray-300">
        {lines.map((line) => (
          <li key={line} className="break-words">
            {line}
          </li>
        ))}
      </ul>
    </div>
  );
}
