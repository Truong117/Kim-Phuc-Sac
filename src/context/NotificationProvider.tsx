import {
  NotificationContext,
  type NotificationContextValue,
} from "@/context/NotificationContext";
import * as notificationService from "@/services/notificationService";
import { markReportNotificationsRead } from "@/services/reportService";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";

const POLL_INTERVAL_MS = 120_000;

export default function NotificationProvider({
  children,
}: React.PropsWithChildren) {
  const [notifications, setNotifications] = useState<
    NotificationContextValue["notifications"]
  >([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const refreshRequestRef = useRef<Promise<void> | null>(null);
  const reportReadRequestsRef = useRef(new Map<number, Promise<number>>());

  const refresh = useCallback((): Promise<void> => {
    if (refreshRequestRef.current) return refreshRequestRef.current;

    setIsLoading(true);
    const request = notificationService
      .getNotifications()
      .then((response) => {
        setNotifications(response.data);
        setUnreadCount(response.unread_count);
        setError(null);
      })
      .catch(() => {
        setError("load");
      })
      .finally(() => {
        if (refreshRequestRef.current === request) {
          refreshRequestRef.current = null;
          setIsLoading(false);
        }
      });

    refreshRequestRef.current = request;
    return request;
  }, []);

  useEffect(() => {
    let intervalId: number | null = null;

    const stopPolling = () => {
      if (intervalId !== null) {
        window.clearInterval(intervalId);
        intervalId = null;
      }
    };

    const startPolling = () => {
      if (document.visibilityState !== "visible" || intervalId !== null) return;
      intervalId = window.setInterval(() => void refresh(), POLL_INTERVAL_MS);
    };

    const handleVisibilityChange = () => {
      if (document.visibilityState === "visible") {
        void refresh();
        startPolling();
      } else {
        stopPolling();
      }
    };

    const handleFocus = () => {
      if (document.visibilityState === "visible") void refresh();
    };

    void refresh();
    startPolling();
    document.addEventListener("visibilitychange", handleVisibilityChange);
    window.addEventListener("focus", handleFocus);

    return () => {
      stopPolling();
      document.removeEventListener("visibilitychange", handleVisibilityChange);
      window.removeEventListener("focus", handleFocus);
    };
  }, [refresh]);

  const markRead = useCallback(
    async (notificationId: number) => {
      const wasUnread = notifications.some(
        (notification) =>
          notification.id === notificationId && !notification.is_read,
      );
      const updated = await notificationService.markNotificationRead(
        notificationId,
      );

      setNotifications((current) =>
        current.map((notification) =>
          notification.id === updated.id ? updated : notification,
        ),
      );
      if (wasUnread) {
        setUnreadCount((current) => Math.max(0, current - 1));
      }
    },
    [notifications],
  );

  const markAllRead = useCallback(async () => {
    await notificationService.markAllNotificationsRead();
    const readAt = new Date().toISOString();
    setNotifications((current) =>
      current.map((notification) => ({
        ...notification,
        is_read: true,
        read_at: notification.read_at ?? readAt,
      })),
    );
    setUnreadCount(0);
  }, []);

  const markReportCommentsRead = useCallback((reportId: number) => {
    const inFlight = reportReadRequestsRef.current.get(reportId);
    if (inFlight) return inFlight;

    const request = markReportNotificationsRead(reportId)
      .then((response) => {
        const markedCount = response.data.marked_read_count;
        const readAt = new Date().toISOString();
        setNotifications((current) =>
          current.map((notification) =>
            notification.type === "REPORT_COMMENT" &&
            notification.reference.type === "DAILY_REPORT" &&
            notification.reference.id === reportId
              ? { ...notification, is_read: true, read_at: readAt }
              : notification,
          ),
        );
        setUnreadCount((current) => Math.max(0, current - markedCount));
        return markedCount;
      })
      .finally(() => {
        reportReadRequestsRef.current.delete(reportId);
      });

    reportReadRequestsRef.current.set(reportId, request);
    return request;
  }, []);

  const value = useMemo<NotificationContextValue>(
    () => ({
      notifications,
      unreadCount,
      isLoading,
      error,
      refresh,
      markRead,
      markAllRead,
      markReportCommentsRead,
    }),
    [
      error,
      isLoading,
      markAllRead,
      markRead,
      markReportCommentsRead,
      notifications,
      refresh,
      unreadCount,
    ],
  );

  return (
    <NotificationContext.Provider value={value}>
      {children}
    </NotificationContext.Provider>
  );
}
