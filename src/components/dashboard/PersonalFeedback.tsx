import ComponentCard from "@/components/common/ComponentCard";
import Badge from "@/components/ui/badge/Badge";
import { ChatIcon } from "@/icons";
import { useNotifications } from "@/hooks/useNotifications";
import type { UserNotification } from "@/types/notifications";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

const formatDateTime = (value: string) =>
  new Intl.DateTimeFormat("vi-VN", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(new Date(value));

export default function PersonalFeedback() {
  const { t } = useTranslation("common", {
    keyPrefix: "overview.feedback",
  });
  const navigate = useNavigate();
  const { notifications, unreadCount, isLoading, error, refresh, markRead } =
    useNotifications();
  const [pendingId, setPendingId] = useState<number | null>(null);
  const [actionError, setActionError] = useState(false);
  const feedback = notifications
    .filter(
      (notification) =>
        notification.type === "REPORT_COMMENT" &&
        notification.reference.type === "DAILY_REPORT",
    )
    .slice(0, 3);

  const handleOpen = async (notification: UserNotification) => {
    setPendingId(notification.id);
    setActionError(false);

    try {
      await markRead(notification.id);
      navigate(`/reports/${notification.reference.id}`);
    } catch {
      setActionError(true);
    } finally {
      setPendingId(null);
    }
  };

  return (
    <ComponentCard title={t("title")} compact>
      <div className="flex items-center justify-between gap-4">
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("description")}
        </p>
        {unreadCount > 0 && (
          <Badge size="sm" color="primary">
            {t("unread", { count: unreadCount })}
          </Badge>
        )}
      </div>

      {isLoading && feedback.length === 0 ? (
        <p className="py-5 text-center text-theme-sm text-gray-500 dark:text-gray-400">
          {t("loading")}
        </p>
      ) : error && feedback.length === 0 ? (
        <div className="rounded-xl bg-error-50 p-4 text-center dark:bg-error-500/10">
          <p className="text-theme-sm text-error-700 dark:text-error-400">
            {t("loadError")}
          </p>
          <button
            type="button"
            onClick={() => void refresh()}
            className="mt-2 rounded text-theme-sm font-semibold text-kps-primary hover:text-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none dark:text-sidebar-selected"
          >
            {t("retry")}
          </button>
        </div>
      ) : feedback.length === 0 ? (
        <p className="rounded-xl bg-gray-50 px-4 py-6 text-center text-theme-sm text-gray-500 dark:bg-white/3 dark:text-gray-400">
          {t("empty")}
        </p>
      ) : (
        <ul className="divide-y divide-gray-100 dark:divide-gray-800">
          {feedback.map((notification) => (
            <li key={notification.id} className="py-1">
              <button
                type="button"
                disabled={pendingId === notification.id}
                onClick={() => void handleOpen(notification)}
                className="flex w-full items-start gap-3 rounded-xl px-3 py-3 text-start transition-colors hover:bg-gray-50 focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none disabled:cursor-wait disabled:opacity-60 dark:hover:bg-white/3"
              >
                <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-kps-primary/10 text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected">
                  <ChatIcon className="size-4" />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="flex items-start justify-between gap-3">
                    <span className="text-theme-sm font-semibold text-gray-900 dark:text-white">
                      {notification.title}
                    </span>
                    {!notification.is_read && (
                      <span
                        className="mt-1.5 size-2 shrink-0 rounded-full bg-kps-primary"
                        aria-label={t("new")}
                      />
                    )}
                  </span>
                  <span className="mt-1 line-clamp-2 text-theme-xs text-gray-500 dark:text-gray-400">
                    {notification.message}
                  </span>
                  <span className="mt-1 block text-theme-xs text-gray-400">
                    {formatDateTime(notification.created_at)}
                  </span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}

      {actionError && (
        <p className="text-theme-sm text-error-600 dark:text-error-400" role="alert">
          {t("actionError")}
        </p>
      )}
    </ComponentCard>
  );
}
