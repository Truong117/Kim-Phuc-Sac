import type { UserNotification } from "@/types/notifications";
import { createContext } from "react";

export type NotificationContextValue = {
  notifications: UserNotification[];
  unreadCount: number;
  isLoading: boolean;
  error: string | null;
  refresh: () => Promise<void>;
  markRead: (notificationId: number) => Promise<void>;
  markAllRead: () => Promise<void>;
  markReportCommentsRead: (reportId: number) => Promise<number>;
};

export const NotificationContext = createContext<
  NotificationContextValue | undefined
>(undefined);
