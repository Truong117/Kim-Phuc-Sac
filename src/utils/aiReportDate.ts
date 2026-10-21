const DATE_KEY_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

export const formatAiReportDate = (date: string): string => {
  if (!DATE_KEY_PATTERN.test(date)) return date;

  const [year, month, day] = date.split("-").map(Number);

  return new Intl.DateTimeFormat("vi-VN", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year, month - 1, day)));
};
