import { useClickOutside } from "@/hooks/useClickOutside";
import { ArrowRightIcon, CalenderIcon } from "@/icons";
import {
  addDaysToDateOnly,
  dateOnlyFromParts,
  dateOnlyInTimeZone,
  formatDateOnly,
  isDateOnly,
  isDateOnlySelectable,
  monthFromDateOnly,
  monthIndex,
  parseDateOnly,
  shiftCalendarMonth,
  type CalendarMonth,
} from "@/utils/dateOnly";
import { cn } from "@/utils";
import {
  useCallback,
  useId,
  useMemo,
  useRef,
  useState,
} from "react";
import { useTranslation } from "react-i18next";

export interface KpsDatePickerProps {
  id?: string;
  value: string;
  onChange: (date: string) => void;
  onBlur?: () => void;
  ariaLabel: string;
  placeholder: string;
  disabled?: boolean;
  required?: boolean;
  clearable?: boolean;
  minDate?: string;
  maxDate?: string;
  allowedDates?: readonly string[];
  isDateDisabled?: (date: string) => boolean;
  preferredDate?: string;
  error?: boolean;
  hint?: string;
  className?: string;
  dialogLabel?: string;
  selectableDateLabel?: string;
  disabledDateLabel?: string;
  calendarHint?: string;
  highlightSelectableDates?: boolean;
}

const datesInMonth = ({ year, month }: CalendarMonth) =>
  new Date(Date.UTC(year, month + 1, 0)).getUTCDate();

const isSameMonth = (date: string, month: CalendarMonth) => {
  const parsed = monthFromDateOnly(date);
  return parsed.year === month.year && parsed.month === month.month;
};

export default function KpsDatePicker({
  id,
  value,
  onChange,
  onBlur,
  ariaLabel,
  placeholder,
  disabled = false,
  required = false,
  clearable = false,
  minDate,
  maxDate,
  allowedDates,
  isDateDisabled,
  preferredDate,
  error = false,
  hint,
  className,
  dialogLabel,
  selectableDateLabel,
  disabledDateLabel,
  calendarHint,
  highlightSelectableDates = false,
}: KpsDatePickerProps) {
  const { t } = useTranslation("common", { keyPrefix: "datePicker" });
  const generatedId = useId();
  const calendarId = `${generatedId}-calendar`;
  const hintId = hint ? `${generatedId}-hint` : undefined;
  const containerRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const calendarRef = useRef<HTMLDivElement>(null);
  const normalizedMinDate = minDate && isDateOnly(minDate) ? minDate : undefined;
  const normalizedMaxDate = maxDate && isDateOnly(maxDate) ? maxDate : undefined;
  const normalizedAllowedDates = useMemo(
    () =>
      allowedDates === undefined
        ? undefined
        : [...new Set(allowedDates.filter(isDateOnly))].sort(),
    [allowedDates],
  );
  const allowedDateSet = useMemo(
    () =>
      normalizedAllowedDates === undefined
        ? undefined
        : new Set(normalizedAllowedDates),
    [normalizedAllowedDates],
  );

  const isSelectable = useCallback(
    (date: string) =>
      isDateOnlySelectable(date, {
        minDate: normalizedMinDate,
        maxDate: normalizedMaxDate,
        allowedDates: allowedDateSet,
        isDateDisabled,
      }),
    [allowedDateSet, isDateDisabled, normalizedMaxDate, normalizedMinDate],
  );

  const selectableAllowedDates = useMemo(
    () => normalizedAllowedDates?.filter(isSelectable) ?? [],
    [isSelectable, normalizedAllowedDates],
  );
  const today = dateOnlyInTimeZone();
  const boundedToday =
    normalizedMinDate && today < normalizedMinDate
      ? normalizedMinDate
      : normalizedMaxDate && today > normalizedMaxDate
        ? normalizedMaxDate
        : today;
  const fallbackDate =
    (preferredDate && isSelectable(preferredDate) && preferredDate) ||
    selectableAllowedDates.at(-1) ||
    (isSelectable(boundedToday) && boundedToday) ||
    normalizedMinDate ||
    normalizedMaxDate ||
    today;
  const initialDate = value && isDateOnly(value) ? value : fallbackDate;
  const [isOpen, setIsOpen] = useState(false);
  const [visibleMonth, setVisibleMonth] = useState<CalendarMonth>(() =>
    monthFromDateOnly(initialDate),
  );
  const [focusedDate, setFocusedDate] = useState(initialDate);

  const closeCalendar = useCallback(() => setIsOpen(false), []);
  useClickOutside(containerRef, closeCalendar);

  const focusDateButton = useCallback((date: string) => {
    window.requestAnimationFrame(() => {
      calendarRef.current
        ?.querySelector<HTMLButtonElement>(`[data-calendar-date="${date}"]`)
        ?.focus();
    });
  }, []);

  const setCalendarFocus = useCallback(
    (date: string) => {
      setFocusedDate(date);
      setVisibleMonth(monthFromDateOnly(date));
      focusDateButton(date);
    },
    [focusDateButton],
  );

  const firstBound = selectableAllowedDates[0] || normalizedMinDate;
  const lastBound = selectableAllowedDates.at(-1) || normalizedMaxDate;
  const firstMonth = firstBound ? monthFromDateOnly(firstBound) : undefined;
  const lastMonth = lastBound ? monthFromDateOnly(lastBound) : undefined;
  const canShowPrevious =
    !firstMonth || monthIndex(visibleMonth) > monthIndex(firstMonth);
  const canShowNext =
    !lastMonth || monthIndex(visibleMonth) < monthIndex(lastMonth);
  const hasInvalidRange = Boolean(
    normalizedMinDate &&
      normalizedMaxDate &&
      normalizedMinDate > normalizedMaxDate,
  );
  const isTriggerDisabled =
    disabled ||
    hasInvalidRange ||
    (normalizedAllowedDates !== undefined && selectableAllowedDates.length === 0);

  const findSelectableDate = useCallback(
    (target: string, direction: -1 | 1): string | null => {
      if (isSelectable(target)) return target;

      if (normalizedAllowedDates !== undefined) {
        const candidates =
          direction > 0
            ? selectableAllowedDates
            : [...selectableAllowedDates].reverse();
        return (
          candidates.find((date) =>
            direction > 0 ? date >= target : date <= target,
          ) ?? null
        );
      }

      let candidate = target;
      for (let attempt = 0; attempt < 3660; attempt += 1) {
        if (
          (normalizedMinDate && candidate < normalizedMinDate) ||
          (normalizedMaxDate && candidate > normalizedMaxDate)
        ) {
          return null;
        }
        if (isSelectable(candidate)) return candidate;
        candidate = addDaysToDateOnly(candidate, direction);
      }

      return null;
    },
    [
      isSelectable,
      normalizedAllowedDates,
      normalizedMaxDate,
      normalizedMinDate,
      selectableAllowedDates,
    ],
  );

  const openCalendar = () => {
    if (isTriggerDisabled) return;
    const dateToFocus =
      (isSelectable(value) && value) ||
      (isSelectable(fallbackDate) && fallbackDate) ||
      selectableAllowedDates.at(-1);
    if (!dateToFocus) return;

    setIsOpen(true);
    setFocusedDate(dateToFocus);
    setVisibleMonth(monthFromDateOnly(dateToFocus));
    focusDateButton(dateToFocus);
  };

  const finishSelection = (date: string) => {
    onChange(date);
    setIsOpen(false);
    window.requestAnimationFrame(() => triggerRef.current?.focus());
  };

  const selectableDatesInVisibleMonth = Array.from(
    { length: datesInMonth(visibleMonth) },
    (_, index) =>
      dateOnlyFromParts(visibleMonth.year, visibleMonth.month, index + 1),
  ).filter(isSelectable);
  const focusableDate =
    isSameMonth(focusedDate, visibleMonth) && isSelectable(focusedDate)
      ? focusedDate
      : selectableDatesInVisibleMonth[0];

  const focusInMonth = (month: CalendarMonth, preferLast = false) => {
    const candidates = Array.from(
      { length: datesInMonth(month) },
      (_, index) => dateOnlyFromParts(month.year, month.month, index + 1),
    ).filter(isSelectable);
    const date = preferLast ? candidates.at(-1) : candidates[0];
    setVisibleMonth(month);
    if (date) {
      setFocusedDate(date);
      focusDateButton(date);
    }
  };

  const changeMonth = (offset: -1 | 1) => {
    const month = shiftCalendarMonth(visibleMonth, offset);
    focusInMonth(month, offset < 0);
  };

  const changeVisibleMonth = (next: CalendarMonth) => {
    const boundedMonth =
      firstMonth && monthIndex(next) < monthIndex(firstMonth)
        ? firstMonth
        : lastMonth && monthIndex(next) > monthIndex(lastMonth)
          ? lastMonth
          : next;
    focusInMonth(boundedMonth);
  };

  const moveFocus = (date: string, dayOffset: number) => {
    const target = addDaysToDateOnly(date, dayOffset);
    const nextDate = findSelectableDate(target, dayOffset < 0 ? -1 : 1);
    if (nextDate) setCalendarFocus(nextDate);
  };

  const moveFocusByMonth = (date: string, offset: -1 | 1) => {
    const parsed = parseDateOnly(date);
    if (!parsed) return;
    const month = shiftCalendarMonth(monthFromDateOnly(date), offset);
    const day = Math.min(parsed.getUTCDate(), datesInMonth(month));
    const target = dateOnlyFromParts(month.year, month.month, day);
    const nextDate = findSelectableDate(target, offset);
    if (nextDate) setCalendarFocus(nextDate);
  };

  const firstWeekday = new Date(
    Date.UTC(visibleMonth.year, visibleMonth.month, 1),
  ).getUTCDay();
  const leadingBlankCount = (firstWeekday + 6) % 7;
  const monthLabel = new Intl.DateTimeFormat("vi-VN", {
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(visibleMonth.year, visibleMonth.month, 1)));
  const weekdayLabels = Array.from({ length: 7 }, (_, index) =>
    new Intl.DateTimeFormat("vi-VN", {
      weekday: "short",
      timeZone: "UTC",
    }).format(new Date(Date.UTC(2024, 0, index + 1))),
  );
  const earliestYear = firstMonth?.year ?? visibleMonth.year - 50;
  const latestYear = lastMonth?.year ?? visibleMonth.year + 50;
  const yearStart = Math.max(earliestYear, visibleMonth.year - 50);
  const yearEnd = Math.min(latestYear, visibleMonth.year + 50);
  const yearOptions = Array.from(
    { length: Math.max(1, yearEnd - yearStart + 1) },
    (_, index) => yearStart + index,
  );

  return (
    <div
      ref={containerRef}
      className={cn("relative", className)}
      onBlurCapture={(event) => {
        const nextTarget = event.relatedTarget;
        if (!nextTarget || !event.currentTarget.contains(nextTarget as Node)) {
          onBlur?.();
        }
      }}
    >
      <button
        id={id}
        ref={triggerRef}
        type="button"
        className={cn(
          "flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border bg-white px-3 py-2.5 text-start text-sm transition-colors outline-none focus-visible:ring-3 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400 disabled:opacity-70 dark:bg-gray-900 dark:text-white dark:disabled:bg-gray-800 dark:disabled:text-gray-500",
          error
            ? "border-error-500 focus-visible:border-error-500 focus-visible:ring-error-500/20 dark:border-error-500"
            : "border-gray-300 text-gray-800 hover:border-kps-primary focus-visible:border-kps-primary focus-visible:ring-kps-primary/20 dark:border-gray-700 dark:hover:border-kps-primary dark:focus-visible:border-kps-primary",
        )}
        aria-label={value ? `${ariaLabel}: ${formatDateOnly(value)}` : ariaLabel}
        aria-haspopup="dialog"
        aria-expanded={isOpen}
        aria-controls={calendarId}
        aria-required={required || undefined}
        aria-invalid={error || undefined}
        aria-describedby={hintId}
        disabled={isTriggerDisabled}
        onClick={() => (isOpen ? closeCalendar() : openCalendar())}
        onKeyDown={(event) => {
          if (event.key === "ArrowDown" && !isOpen) {
            event.preventDefault();
            openCalendar();
          }
        }}
      >
        <span className={value ? undefined : "text-gray-400 dark:text-gray-500"}>
          {value ? formatDateOnly(value) : placeholder}
        </span>
        <CalenderIcon className="size-5 shrink-0 text-kps-primary dark:text-sidebar-selected" aria-hidden="true" />
      </button>

      {hint && (
        <p
          id={hintId}
          className={cn(
            "mt-1.5 text-theme-xs",
            error ? "text-error-500" : "text-gray-500 dark:text-gray-400",
          )}
        >
          {hint}
        </p>
      )}

      {isOpen && (
        <div
          ref={calendarRef}
          id={calendarId}
          role="dialog"
          aria-label={dialogLabel ?? t("dialogLabel")}
          className="absolute end-0 z-50 mt-2 max-h-[calc(100vh-2rem)] w-80 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-lg dark:border-gray-700 dark:bg-gray-900"
          onKeyDown={(event) => {
            if (event.key === "Escape") {
              event.preventDefault();
              closeCalendar();
              triggerRef.current?.focus();
            }
          }}
        >
          <div className="mb-3 flex items-center justify-between gap-2">
            <button
              type="button"
              className="flex size-9 shrink-0 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800 focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-30 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
              aria-label={t("previousMonth")}
              disabled={!canShowPrevious}
              onClick={() => changeMonth(-1)}
            >
              <ArrowRightIcon className="size-4 rotate-180 rtl:rotate-0" aria-hidden="true" />
            </button>

            <div className="flex min-w-0 flex-1 items-center justify-center gap-1">
              <label className="sr-only" htmlFor={`${calendarId}-month`}>
                {t("chooseMonth")}
              </label>
              <select
                id={`${calendarId}-month`}
                value={visibleMonth.month}
                aria-label={t("chooseMonth")}
                onChange={(event) =>
                  changeVisibleMonth({
                    year: visibleMonth.year,
                    month: Number(event.target.value),
                  })
                }
                className="min-w-0 rounded-lg border border-transparent bg-transparent px-1 py-1.5 text-theme-sm font-semibold capitalize text-gray-900 outline-none hover:border-gray-200 focus:border-kps-primary focus:ring-2 focus:ring-kps-primary/20 dark:text-white dark:hover:border-gray-700"
              >
                {Array.from({ length: 12 }, (_, month) => {
                  const candidate = { year: visibleMonth.year, month };
                  const unavailable =
                    (firstMonth && monthIndex(candidate) < monthIndex(firstMonth)) ||
                    (lastMonth && monthIndex(candidate) > monthIndex(lastMonth));
                  const label = new Intl.DateTimeFormat("vi-VN", {
                    month: "long",
                    timeZone: "UTC",
                  }).format(new Date(Date.UTC(2024, month, 1)));
                  return (
                    <option key={month} value={month} disabled={unavailable}>
                      {label}
                    </option>
                  );
                })}
              </select>
              <label className="sr-only" htmlFor={`${calendarId}-year`}>
                {t("chooseYear")}
              </label>
              <select
                id={`${calendarId}-year`}
                value={visibleMonth.year}
                aria-label={t("chooseYear")}
                onChange={(event) =>
                  changeVisibleMonth({
                    year: Number(event.target.value),
                    month: visibleMonth.month,
                  })
                }
                className="rounded-lg border border-transparent bg-transparent px-1 py-1.5 text-theme-sm font-semibold text-gray-900 outline-none hover:border-gray-200 focus:border-kps-primary focus:ring-2 focus:ring-kps-primary/20 dark:text-white dark:hover:border-gray-700"
              >
                {yearOptions.map((year) => (
                  <option key={year} value={year}>
                    {year}
                  </option>
                ))}
              </select>
            </div>

            <button
              type="button"
              className="flex size-9 shrink-0 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800 focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-30 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
              aria-label={t("nextMonth")}
              disabled={!canShowNext}
              onClick={() => changeMonth(1)}
            >
              <ArrowRightIcon className="size-4 rtl:rotate-180" aria-hidden="true" />
            </button>
          </div>

          <p className="mb-2 text-center text-theme-xs font-medium text-gray-500 capitalize dark:text-gray-400">
            {monthLabel}
          </p>

          <div className="grid grid-cols-7 gap-1">
            {weekdayLabels.map((weekday, index) => (
              <span
                key={`${weekday}-${index}`}
                className="flex size-9 items-center justify-center text-theme-xs font-medium text-gray-400 dark:text-gray-500"
              >
                {weekday}
              </span>
            ))}
            {Array.from({ length: leadingBlankCount }, (_, index) => (
              <span key={`blank-${index}`} className="size-9" aria-hidden="true" />
            ))}
            {Array.from({ length: datesInMonth(visibleMonth) }, (_, index) => {
              const day = index + 1;
              const date = dateOnlyFromParts(
                visibleMonth.year,
                visibleMonth.month,
                day,
              );
              const selectable = isSelectable(date);
              const selected = value === date;
              const isToday = today === date;
              const fullDate = new Intl.DateTimeFormat("vi-VN", {
                weekday: "long",
                day: "2-digit",
                month: "2-digit",
                year: "numeric",
                timeZone: "UTC",
              }).format(parseDateOnly(date)!);
              const stateLabel = selectable
                ? (selectableDateLabel ?? t("selectable"))
                : (disabledDateLabel ?? t("unavailable"));

              return (
                <button
                  key={date}
                  type="button"
                  data-calendar-date={date}
                  disabled={!selectable}
                  tabIndex={selectable && date === focusableDate ? 0 : -1}
                  aria-pressed={selected}
                  aria-current={isToday ? "date" : undefined}
                  aria-label={`${fullDate}. ${stateLabel}${isToday ? `. ${t("today")}` : ""}`}
                  className={cn(
                    "flex size-9 items-center justify-center rounded-lg border text-sm font-medium transition-colors focus-visible:ring-3 focus-visible:ring-kps-primary/25 focus-visible:outline-none",
                    selected
                      ? "border-kps-primary bg-kps-primary text-white"
                      : selectable
                        ? cn(
                            "text-gray-700 hover:border-kps-primary/40 hover:bg-kps-primary/10 hover:text-kps-primary dark:text-gray-300 dark:hover:text-sidebar-selected",
                            highlightSelectableDates
                              ? "border-kps-primary/30 bg-kps-primary/10 text-kps-primary dark:border-kps-primary/40 dark:bg-kps-primary/20 dark:text-sidebar-selected"
                              : isToday
                                ? "border-kps-primary/60"
                                : "border-transparent",
                          )
                        : "cursor-not-allowed border-transparent text-gray-300 opacity-50 dark:text-gray-600",
                  )}
                  onClick={() => finishSelection(date)}
                  onKeyDown={(event) => {
                    if (event.key === "ArrowLeft") {
                      event.preventDefault();
                      moveFocus(date, -1);
                    } else if (event.key === "ArrowRight") {
                      event.preventDefault();
                      moveFocus(date, 1);
                    } else if (event.key === "ArrowUp") {
                      event.preventDefault();
                      moveFocus(date, -7);
                    } else if (event.key === "ArrowDown") {
                      event.preventDefault();
                      moveFocus(date, 7);
                    } else if (event.key === "Home") {
                      event.preventDefault();
                      const weekday = (parseDateOnly(date)!.getUTCDay() + 6) % 7;
                      moveFocus(date, -weekday);
                    } else if (event.key === "End") {
                      event.preventDefault();
                      const weekday = (parseDateOnly(date)!.getUTCDay() + 6) % 7;
                      moveFocus(date, 6 - weekday);
                    } else if (event.key === "PageUp") {
                      event.preventDefault();
                      moveFocusByMonth(date, -1);
                    } else if (event.key === "PageDown") {
                      event.preventDefault();
                      moveFocusByMonth(date, 1);
                    }
                  }}
                >
                  {day}
                </button>
              );
            })}
          </div>

          {(calendarHint || (clearable && value)) && (
            <div className="mt-3 flex items-start justify-between gap-3 border-t border-gray-100 pt-3 dark:border-gray-800">
              {calendarHint ? (
                <p className="text-theme-xs leading-5 text-gray-500 dark:text-gray-400">
                  {calendarHint}
                </p>
              ) : (
                <span />
              )}
              {clearable && value && (
                <button
                  type="button"
                  className="shrink-0 rounded-lg px-2 py-1 text-theme-xs font-semibold text-kps-primary transition-colors hover:bg-kps-primary/10 focus-visible:ring-2 focus-visible:ring-kps-primary/25 focus-visible:outline-none dark:text-sidebar-selected"
                  onClick={() => finishSelection("")}
                >
                  {t("clear")}
                </button>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
