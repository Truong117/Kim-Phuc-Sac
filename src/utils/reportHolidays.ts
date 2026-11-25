import { parseDateOnly } from "./dateOnly";
import type { ReportHoliday } from "@/types/reportHolidays";

export const countScheduledReportDays = (
  start: string,
  end: string,
  workingDays: readonly number[],
): number => {
  const first = parseDateOnly(start);
  const last = parseDateOnly(end);
  if (!first || !last || start > end) return 0;
  const length = Math.round((last.getTime() - first.getTime()) / 86400000) + 1;
  const weekdays = new Set(workingDays);
  let count = Math.floor(length / 7) * weekdays.size;
  for (let offset = 0; offset < length % 7; offset++) {
    if (weekdays.has(((first.getUTCDay() + offset + 6) % 7) + 1)) count++;
  }
  return count;
};

export const overlappingReportHoliday = (
  rows: readonly ReportHoliday[],
  start: string,
  end: string,
  exceptId?: number,
) =>
  rows.find(
    (row) =>
      row.id !== exceptId &&
      row.is_active &&
      row.start_date <= end &&
      row.end_date >= start,
  );

export const reportHolidayStatus = (holiday: ReportHoliday, today: string) =>
  !holiday.is_active
    ? "inactive"
    : holiday.end_date < today
      ? "past"
      : holiday.start_date > today
        ? "upcoming"
        : "current";
