import ComponentCard from "@/components/common/ComponentCard";
import type { LockedReportAmendment } from "@/types/reports";
import { useTranslation } from "react-i18next";

const eventTime = new Intl.DateTimeFormat("vi-VN", {
  dateStyle: "short",
  timeStyle: "short",
  timeZone: "Asia/Ho_Chi_Minh",
});

export default function LockedReportAmendmentHistory({
  amendment,
}: {
  amendment: LockedReportAmendment;
}) {
  const { t } = useTranslation("common", {
    keyPrefix: "reportDetail.lockedAmendment",
  });
  if (!amendment.history.length && !amendment.ai_may_be_stale) return null;

  return (
    <ComponentCard title={t("history")} compact>
      {amendment.ai_may_be_stale && (
        <p className="rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-950 dark:text-warning-200">
          {t("aiNotice")}
        </p>
      )}
      {amendment.history.length > 0 && (
        <ol className="divide-y divide-gray-100 dark:divide-gray-800">
          {amendment.history.map((entry) => (
            <li key={entry.id} className="space-y-1 py-3">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="font-semibold text-gray-900 dark:text-white">
                  {entry.editor_name}
                </span>
                <time
                  dateTime={entry.created_at}
                  className="text-sm text-gray-600 dark:text-gray-400"
                >
                  {eventTime.format(new Date(entry.created_at))}
                </time>
              </div>
              <p className="text-sm break-words whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                {entry.reason}
              </p>
            </li>
          ))}
        </ol>
      )}
      {amendment.history_truncated && (
        <p className="text-sm text-gray-600 dark:text-gray-400">
          {t("historyTruncated")}
        </p>
      )}
    </ComponentCard>
  );
}
