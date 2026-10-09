import ReportStatusBadge from "@/components/reports/ReportStatusBadge";
import type { ReportWorkItem } from "@/types/reports";
import { useTranslation } from "react-i18next";

interface ReportWorkItemDetailsProps {
  items: ReportWorkItem[];
}

export default function ReportWorkItemDetails({
  items,
}: ReportWorkItemDetailsProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "reportDetail.items",
  });

  return (
    <div className="space-y-4">
      {items.map((item, index) => (
        <article
          key={item.id}
          className="rounded-xl border border-gray-200 bg-gray-50/60 p-4 sm:p-5 dark:border-gray-800 dark:bg-gray-900/50"
        >
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h3 className="font-semibold text-gray-900 dark:text-white">
              {t("itemTitle", { number: index + 1 })}
            </h3>
            <ReportStatusBadge status={item.status} />
          </div>
          <dl className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div>
              <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                {t("content")}
              </dt>
              <dd className="mt-1 whitespace-pre-wrap text-theme-sm text-gray-800 dark:text-white/90">
                {item.content}
              </dd>
            </div>
            <div>
              <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                {t("result")}
              </dt>
              <dd className="mt-1 whitespace-pre-wrap text-theme-sm text-gray-800 dark:text-white/90">
                {item.result}
              </dd>
            </div>
            {item.note && (
              <div className="lg:col-span-2">
                <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  {t("note")}
                </dt>
                <dd className="mt-1 whitespace-pre-wrap text-theme-sm text-gray-700 dark:text-gray-300">
                  {item.note}
                </dd>
              </div>
            )}
          </dl>
        </article>
      ))}
    </div>
  );
}
