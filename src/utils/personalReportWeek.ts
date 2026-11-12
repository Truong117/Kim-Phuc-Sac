import { addDaysToDateOnly, parseDateOnly } from "./dateOnly";

export const lastReportSaturday = (today: string): string => {
  const day = parseDateOnly(today)?.getUTCDay() ?? 6;
  return addDaysToDateOnly(today, -((day + 1) % 7));
};

export const personalReportWeek = (
  value: string | null,
  today: string,
): string => {
  const parsed = value ? parseDateOnly(value) : null;
  const maxWeek = addDaysToDateOnly(
    lastReportSaturday(today),
    parseDateOnly(today)?.getUTCDay() === 6 ? 0 : 7,
  );
  return value && parsed && value <= maxWeek
    ? value
    : lastReportSaturday(today);
};
