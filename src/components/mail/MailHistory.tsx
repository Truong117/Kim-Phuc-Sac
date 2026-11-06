import { useTranslation } from "react-i18next";
import type { MailHistoryEntry } from "@/services/mailService";
import ComponentCard from "@/components/common/ComponentCard";
import MailMessageStatus from "./MailMessageStatus";

export default function MailHistory({
  entries,
  title,
  desc,
}: {
  entries: MailHistoryEntry[];
  title?: string;
  desc?: string;
}) {
  const { t } = useTranslation();
  return (
    <ComponentCard
      title={title || t("mailUi.historyTitle")}
      desc={desc || t("mailUi.historyDescription")}
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
                {entry.kind === "internal" && entry.subject && (
                  <p className="font-medium break-all text-gray-900 dark:text-white">
                    {entry.subject}
                  </p>
                )}
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
              <div className="ms-auto max-w-full text-end">
                <MailMessageStatus status={entry.status} />
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
