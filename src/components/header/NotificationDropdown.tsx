import { Dropdown } from "@/components/ui/dropdown/Dropdown";
import { useNotifications } from "@/hooks/useNotifications";
import { BellIcon, ChatIcon, CloseIcon } from "@/icons";
import type { UserNotification } from "@/types/notifications";
import { cn } from "@/utils";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

const formatNotificationTime = (value: string) => {
  const date = new Date(value);
  const elapsedSeconds = Math.round((date.getTime() - Date.now()) / 1000);
  const formatter = new Intl.RelativeTimeFormat("vi-VN", { numeric: "auto" });

  if (Math.abs(elapsedSeconds) < 60) return formatter.format(elapsedSeconds, "second");
  const elapsedMinutes = Math.round(elapsedSeconds / 60);
  if (Math.abs(elapsedMinutes) < 60) return formatter.format(elapsedMinutes, "minute");
  const elapsedHours = Math.round(elapsedMinutes / 60);
  if (Math.abs(elapsedHours) < 24) return formatter.format(elapsedHours, "hour");

  return new Intl.DateTimeFormat("vi-VN", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(date);
};

const getNotificationPath = (notification: UserNotification) => {
  if (
    notification.type === "REPORT_COMMENT" &&
    notification.reference.type === "DAILY_REPORT"
  ) {
    return `/reports/${notification.reference.id}`;
  }

  return null;
};

export default function NotificationDropdown() {
  const { t } = useTranslation("common", { keyPrefix: "notifications" });
  const navigate = useNavigate();
  const {
    notifications,
    unreadCount,
    isLoading,
    error,
    refresh,
    markRead,
    markAllRead,
  } = useNotifications();
  const [isOpen, setIsOpen] = useState(false);
  const [pendingId, setPendingId] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const toggleDropdown = () => {
    setIsOpen((current) => {
      const next = !current;
      if (next) void refresh();
      return next;
    });
  };

  const handleNotificationClick = async (notification: UserNotification) => {
    setPendingId(notification.id);
    setActionError(null);

    try {
      await markRead(notification.id);
      setIsOpen(false);
      const path = getNotificationPath(notification);
      if (path) navigate(path);
    } catch {
      setActionError(t("actionError"));
    } finally {
      setPendingId(null);
    }
  };

  const handleMarkAllRead = async () => {
    setActionError(null);
    try {
      await markAllRead();
    } catch {
      setActionError(t("actionError"));
    }
  };

  return (
    <div className="relative">
      <button
        type="button"
        className="dropdown-toggle relative flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white"
        onClick={toggleDropdown}
        aria-label={t("open")}
        aria-expanded={isOpen}
      >
        <BellIcon className="size-5" aria-hidden="true" />
        {unreadCount > 0 && (
          <span className="absolute -top-1 -end-1 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-error-500 px-1 text-theme-xs font-semibold text-white ring-2 ring-white dark:ring-gray-900">
            {unreadCount > 99 ? "99+" : unreadCount}
          </span>
        )}
      </button>

      <Dropdown
        isOpen={isOpen}
        onClose={() => setIsOpen(false)}
        className="absolute -inset-s-13.5 mt-4.25 flex max-h-120 w-87.5 flex-col rounded-2xl border border-gray-200 bg-white p-3 shadow-theme-lg sm:w-90.25 xl:inset-s-auto xl:inset-e-0 dark:border-gray-800 dark:bg-gray-dark"
      >
        <div className="mb-2 flex items-center justify-between gap-3 border-b border-gray-100 pb-3 dark:border-gray-700">
          <div>
            <h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">
              {t("title")}
            </h2>
            {unreadCount > 0 && (
              <p className="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                {t("unreadCount", { count: unreadCount })}
              </p>
            )}
          </div>
          <button
            type="button"
            onClick={() => setIsOpen(false)}
            aria-label={t("close")}
            className="flex size-9 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200"
          >
            <CloseIcon className="size-5" aria-hidden="true" />
          </button>
        </div>

        <div className="custom-scrollbar min-h-24 flex-1 overflow-y-auto">
          {isLoading && notifications.length === 0 ? (
            <p className="px-4 py-8 text-center text-theme-sm text-gray-500 dark:text-gray-400">
              {t("loading")}
            </p>
          ) : error && notifications.length === 0 ? (
            <div className="px-4 py-7 text-center">
              <p className="text-theme-sm text-error-600 dark:text-error-400">
                {t("loadError")}
              </p>
              <button
                type="button"
                onClick={() => void refresh()}
                className="mt-3 rounded-lg px-3 py-2 text-theme-sm font-semibold text-kps-primary hover:bg-kps-primary/10 focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none dark:text-sidebar-selected"
              >
                {t("retry")}
              </button>
            </div>
          ) : notifications.length === 0 ? (
            <p className="px-4 py-8 text-center text-theme-sm text-gray-500 dark:text-gray-400">
              {t("empty")}
            </p>
          ) : (
            <ul className="flex flex-col">
              {notifications.map((notification) => (
                <li key={notification.id}>
                  <button
                    type="button"
                    disabled={pendingId === notification.id}
                    onClick={() => void handleNotificationClick(notification)}
                    className={cn(
                      "flex w-full gap-3 border-b border-gray-100 px-3 py-3 text-start transition-colors last:border-b-0 hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-kps-primary/30 focus-visible:outline-none disabled:cursor-wait disabled:opacity-60 dark:border-gray-800 dark:hover:bg-white/5",
                      !notification.is_read &&
                        "bg-kps-primary/6 dark:bg-kps-primary/10",
                    )}
                  >
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-kps-primary/10 text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected">
                      <ChatIcon className="size-5" aria-hidden="true" />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="flex items-start justify-between gap-2">
                        <span className="text-theme-sm font-semibold text-gray-800 dark:text-white/90">
                          {notification.title}
                        </span>
                        {!notification.is_read && (
                          <span
                            aria-hidden="true"
                            className="mt-1.5 size-2 shrink-0 rounded-full bg-kps-primary"
                          />
                        )}
                      </span>
                      <span className="mt-1 block text-theme-xs leading-5 text-gray-500 dark:text-gray-400">
                        {notification.message}
                      </span>
                      <span className="mt-1.5 flex flex-wrap items-center gap-x-1.5 text-theme-xs text-gray-400 dark:text-gray-500">
                        {notification.actor && (
                          <>
                            <span>{notification.actor.name}</span>
                            <span aria-hidden="true">•</span>
                          </>
                        )}
                        <span>{formatNotificationTime(notification.created_at)}</span>
                      </span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        {actionError && (
          <p className="mt-2 text-center text-theme-xs text-error-600 dark:text-error-400">
            {actionError}
          </p>
        )}

        {unreadCount > 0 && (
          <button
            type="button"
            onClick={() => void handleMarkAllRead()}
            className="mt-3 rounded-lg border border-gray-300 bg-white px-4 py-2 text-center text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
          >
            {t("markAllRead")}
          </button>
        )}
      </Dropdown>
    </div>
  );
}
