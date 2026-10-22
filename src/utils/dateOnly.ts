export interface CalendarMonth {
  year: number;
  month: number;
}

export const DATE_ONLY_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

export const parseDateOnly = (value: string): Date | null => {
  if (!DATE_ONLY_PATTERN.test(value)) return null;

  const [year, month, day] = value.split("-").map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));

  if (
    date.getUTCFullYear() !== year ||
    date.getUTCMonth() !== month - 1 ||
    date.getUTCDate() !== day
  ) {
    return null;
  }

  return date;
};

export const isDateOnly = (value: string) => parseDateOnly(value) !== null;

export const dateOnlyFromParts = (
  year: number,
  month: number,
  day: number,
) =>
  `${String(year).padStart(4, "0")}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;

export const formatDateOnly = (value: string): string => {
  if (!isDateOnly(value)) return value;

  const [year, month, day] = value.split("-");
  return `${day}/${month}/${year}`;
};

export const monthFromDateOnly = (value: string): CalendarMonth => {
  const date = parseDateOnly(value);
  if (!date) return { year: 1970, month: 0 };

  return { year: date.getUTCFullYear(), month: date.getUTCMonth() };
};

export const monthIndex = ({ year, month }: CalendarMonth) =>
  year * 12 + month;

export const shiftCalendarMonth = (
  { year, month }: CalendarMonth,
  offset: number,
): CalendarMonth => {
  const shifted = new Date(Date.UTC(year, month + offset, 1));
  return { year: shifted.getUTCFullYear(), month: shifted.getUTCMonth() };
};

export const addDaysToDateOnly = (value: string, days: number): string => {
  const date = parseDateOnly(value);
  if (!date) return value;

  date.setUTCDate(date.getUTCDate() + days);
  return dateOnlyFromParts(
    date.getUTCFullYear(),
    date.getUTCMonth(),
    date.getUTCDate(),
  );
};

export const dateOnlyInTimeZone = (
  date = new Date(),
  timeZone = "Asia/Ho_Chi_Minh",
): string => {
  const parts = new Intl.DateTimeFormat("en-CA", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    timeZone,
  }).formatToParts(date);
  const values = Object.fromEntries(
    parts
      .filter((part) => ["year", "month", "day"].includes(part.type))
      .map((part) => [part.type, part.value]),
  );

  return `${values.year}-${values.month}-${values.day}`;
};

export const compareDateOnly = (first: string, second: string) =>
  first.localeCompare(second);

export interface DateOnlySelectionRules {
  minDate?: string;
  maxDate?: string;
  allowedDates?: ReadonlySet<string>;
  isDateDisabled?: (date: string) => boolean;
}

export const isDateOnlySelectable = (
  value: string,
  rules: DateOnlySelectionRules = {},
) =>
  isDateOnly(value) &&
  (!rules.minDate || value >= rules.minDate) &&
  (!rules.maxDate || value <= rules.maxDate) &&
  (!rules.allowedDates || rules.allowedDates.has(value)) &&
  !rules.isDateDisabled?.(value);
