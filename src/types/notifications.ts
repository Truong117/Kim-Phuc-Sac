export type UserNotification = {
  id: number;
  type: "REPORT_COMMENT";
  title: string;
  message: string;
  actor: { id: number; name: string } | null;
  reference: { type: "DAILY_REPORT"; id: number };
  is_read: boolean;
  read_at: string | null;
  created_at: string;
};

export type NotificationListResponse = {
  data: UserNotification[];
  unread_count: number;
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type MarkAllNotificationsReadResponse = {
  data: { marked_read_count: number };
};
