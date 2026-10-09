import { apiRequest } from "@/services/apiClient";
import type {
  MarkAllNotificationsReadResponse,
  NotificationListResponse,
  UserNotification,
} from "@/types/notifications";

type DataResponse<T> = { data: T };

export const getNotifications = (
  signal?: AbortSignal,
): Promise<NotificationListResponse> =>
  apiRequest<NotificationListResponse>("/api/notifications?per_page=20", {
    signal,
  });

export const markNotificationRead = async (
  notificationId: number,
): Promise<UserNotification> => {
  const response = await apiRequest<DataResponse<UserNotification>>(
    `/api/notifications/${notificationId}/read`,
    { method: "PATCH" },
  );
  return response.data;
};

export const markAllNotificationsRead = () =>
  apiRequest<MarkAllNotificationsReadResponse>("/api/notifications/read-all", {
    method: "PATCH",
  });
