import { useTranslation } from "react-i18next";
import type { MailHistoryEntry } from "@/services/mailService";
import ComponentCard from "@/components/common/ComponentCard";

export default function MailHistory({
  entries,
}: {
  entries: MailHistoryEntry[];
}) {
  const { t } = useTranslation();
  return (
    <ComponentCard
      title={t("mailUi.historyTitle")}
      desc={t("mailUi.historyDescription")}
    >
      {entries.length === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400">
          {t("mailUi.emptyHistory")}
        </p>
      ) : (
        <ul className="divide-y divide-gray-200 dark:divide-gray-800">
          {entries.map((entry) => (
            <li
              key={entry.id}
              className="flex flex-wrap items-start justify-between gap-3 py-3 text-sm"
            >
              <div className="min-w-0">
                <p className="break-all text-gray-900 dark:text-white">
                  {entry.recipient_email}
                </p>
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  {new Intl.DateTimeFormat("vi-VN", {
                    dateStyle: "short",
                    timeStyle: "short",
                    timeZone: "Asia/Ho_Chi_Minh",
                  }).format(new Date(entry.created_at))}
                </p>
              </div>
              <div className="text-end">
                <span className="rounded-md bg-gray-100 px-2 py-1 text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                  {t(`mailUi.messageStates.${entry.status}`, {
                    defaultValue: t("mailUi.messageStates.unknown"),
                  })}
                </span>
                {entry.error_code && (
                  <p className="mt-2 text-xs text-error-600 dark:text-error-400">
                    {t(`mailUi.errors.${entry.error_code}`, {
                      defaultValue: t("mailUi.deliveryError"),
                    })}
                  </p>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </ComponentCard>
  );
}
