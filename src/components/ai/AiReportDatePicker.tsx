import { ArrowRightIcon, CalenderIcon } from "@/icons";
import { useClickOutside } from "@/hooks/useClickOutside";
import { formatAiReportDate } from "@/utils/aiReportDate";
import { cn } from "@/utils";
import { useCallback, useMemo, useRef, useState } from "react";
import { useTranslation } from "react-i18next";

interface CalendarMonth {
  year: number;
  month: number;
}

interface AiReportDatePickerProps {
  value: string;
  availableDates: string[];
  onChange: (date: string) => void;
  ariaLabel: string;
  placeholder: string;
  disabled?: boolean;
}

const DATE_KEY_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

const parseDateKey = (date: string): Date => {
  const [year, month, day] = date.split("-").map(Number);
  return new Date(Date.UTC(year, month - 1, day));
};

const monthFromDate = (date: string): CalendarMonth => {
  const parsed = parseDateKey(date);
  return { year: parsed.getUTCFullYear(), month: parsed.getUTCMonth() };
};

const monthIndex = ({ year, month }: CalendarMonth) => year * 12 + month;

const shiftMonth = (
  { year, month }: CalendarMonth,
  offset: number,
): CalendarMonth => {
  const shifted = new Date(Date.UTC(year, month + offset, 1));
  return { year: shifted.getUTCFullYear(), month: shifted.getUTCMonth() };
};

const dateKey = (year: number, month: number, day: number) =>
  `${year}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;

export default function AiReportDatePicker({
  value,
  availableDates,
  onChange,
  ariaLabel,
  placeholder,
  disabled = false,
}: AiReportDatePickerProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "aiDailyReport.calendar",
  });
  const containerRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const calendarRef = useRef<HTMLDivElement>(null);
  const normalizedDates = useMemo(
    () =>
      [
        ...new Set(
          availableDates.filter((date) => DATE_KEY_PATTERN.test(date)),
        ),
      ].sort(),
    [availableDates],
  );
  const dateSet = useMemo(() => new Set(normalizedDates), [normalizedDates]);
  const latestDate = normalizedDates.at(-1) ?? "";
  const earliestDate = normalizedDates[0] ?? "";
  const initialMonth =
    value && DATE_KEY_PATTERN.test(value) ? value : latestDate;
  const [isOpen, setIsOpen] = useState(false);
  const [visibleMonth, setVisibleMonth] = useState<CalendarMonth>(() =>
    monthFromDate(initialMonth || "1970-01-01"),
  );

  const closeCalendar = useCallback(() => setIsOpen(false), []);
  useClickOutside(containerRef, closeCalendar);

  const focusDateButton = (date: string) => {
    window.requestAnimationFrame(() => {
      calendarRef.current
        ?.querySelector<HTMLButtonElement>(`[data-calendar-date="${date}"]`)
        ?.focus();
    });
  };

  const openCalendar = () => {
    if (disabled || !latestDate) return;
    const focusDate = dateSet.has(value) ? value : latestDate;
    setVisibleMonth(monthFromDate(focusDate));
    setIsOpen(true);
    focusDateButton(focusDate);
  };

  const selectDate = (date: string) => {
    onChange(date);
    setIsOpen(false);
    window.requestAnimationFrame(() => triggerRef.current?.focus());
  };

  const moveFocus = (currentDate: string, direction: -1 | 1) => {
    const index = normalizedDates.indexOf(currentDate);
    const nextDate = normalizedDates[index + direction];
    if (!nextDate) return;
    setVisibleMonth(monthFromDate(nextDate));
    focusDateButton(nextDate);
  };

  const availableInVisibleMonth = normalizedDates.filter((date) => {
    const month = monthFromDate(date);
    return (
      month.year === visibleMonth.year && month.month === visibleMonth.month
    );
  });
  const focusedDate =
    dateSet.has(value) && availableInVisibleMonth.includes(value)
      ? value
      : availableInVisibleMonth.at(-1);
  const earliestMonth = earliestDate
    ? monthFromDate(earliestDate)
    : visibleMonth;
  const latestMonth = latestDate ? monthFromDate(latestDate) : visibleMonth;
  const canShowPrevious = monthIndex(visibleMonth) > monthIndex(earliestMonth);
  const canShowNext = monthIndex(visibleMonth) < monthIndex(latestMonth);
  const firstWeekday = new Date(
    Date.UTC(visibleMonth.year, visibleMonth.month, 1),
  ).getUTCDay();
  const leadingBlankCount = (firstWeekday + 6) % 7;
  const daysInMonth = new Date(
    Date.UTC(visibleMonth.year, visibleMonth.month + 1, 0),
  ).getUTCDate();
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

  const changeMonth = (offset: -1 | 1) => {
    const nextMonth = shiftMonth(visibleMonth, offset);
    setVisibleMonth(nextMonth);
    const datesInNextMonth = normalizedDates.filter((date) => {
      const month = monthFromDate(date);
      return month.year === nextMonth.year && month.month === nextMonth.month;
    });
    const focusDate =
      offset < 0 ? datesInNextMonth.at(-1) : datesInNextMonth[0];
    if (focusDate) focusDateButton(focusDate);
  };

  return (
    <div ref={containerRef} className="relative mt-2">
      <button
        ref={triggerRef}
        type="button"
        className="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-start text-sm text-gray-800 transition-colors outline-none hover:border-kps-primary focus-visible:border-kps-primary focus-visible:ring-3 focus-visible:ring-kps-primary/20 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400 disabled:opacity-70 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:hover:border-kps-primary dark:focus-visible:border-kps-primary dark:disabled:bg-gray-800 dark:disabled:text-gray-500"
        aria-label={
          value ? `${ariaLabel}: ${formatAiReportDate(value)}` : ariaLabel
        }
        aria-haspopup="dialog"
        aria-expanded={isOpen}
        aria-controls="ai-report-calendar"
        disabled={disabled}
        onClick={() => (isOpen ? closeCalendar() : openCalendar())}
        onKeyDown={(event) => {
          if (event.key === "ArrowDown" && !isOpen) {
            event.preventDefault();
            openCalendar();
          }
        }}
      >
        <span>{value ? formatAiReportDate(value) : placeholder}</span>
        <CalenderIcon className="size-5 shrink-0 text-kps-primary dark:text-sidebar-selected" />
      </button>

      {isOpen && (
        <div
          ref={calendarRef}
          id="ai-report-calendar"
          role="dialog"
          aria-label={t("dialogLabel")}
          className="absolute start-0 z-40 mt-2 w-full min-w-72 rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-lg sm:w-80 dark:border-gray-700 dark:bg-gray-900"
          onKeyDown={(event) => {
            if (event.key === "Escape") {
              event.preventDefault();
              closeCalendar();
              triggerRef.current?.focus();
            }
          }}
        >
          <div className="mb-4 flex items-center justify-between gap-3">
            <button
              type="button"
              className="flex size-9 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800 focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-30 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
              aria-label={t("previousMonth")}
              disabled={!canShowPrevious}
              onClick={() => changeMonth(-1)}
            >
              <ArrowRightIcon className="size-4 rotate-180 rtl:rotate-0" />
            </button>
            <p className="text-sm font-semibold text-gray-900 capitalize dark:text-white">
              {monthLabel}
            </p>
            <button
              type="button"
              className="flex size-9 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800 focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-30 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
              aria-label={t("nextMonth")}
              disabled={!canShowNext}
              onClick={() => changeMonth(1)}
            >
              <ArrowRightIcon className="size-4 rtl:rotate-180" />
            </button>
          </div>

          <div className="grid grid-cols-7 gap-1">
            {weekdayLabels.map((weekday) => (
              <span
                key={weekday}
                className="flex size-9 items-center justify-center text-theme-xs font-medium text-gray-400 dark:text-gray-500"
              >
                {weekday}
              </span>
            ))}
            {Array.from({ length: leadingBlankCount }, (_, index) => (
              <span
                key={`blank-${index}`}
                className="size-9"
                aria-hidden="true"
              />
            ))}
            {Array.from({ length: daysInMonth }, (_, index) => {
              const day = index + 1;
              const currentDate = dateKey(
                visibleMonth.year,
                visibleMonth.month,
                day,
              );
              const available = dateSet.has(currentDate);
              const selected = value === currentDate;
              const fullDate = new Intl.DateTimeFormat("vi-VN", {
                weekday: "long",
                day: "2-digit",
                month: "2-digit",
                year: "numeric",
                timeZone: "UTC",
              }).format(parseDateKey(currentDate));

              return (
                <button
                  key={currentDate}
                  type="button"
                  data-calendar-date={currentDate}
                  disabled={!available}
                  tabIndex={available && currentDate === focusedDate ? 0 : -1}
                  aria-pressed={selected}
                  aria-label={`${fullDate}. ${t(available ? "available" : "unavailable")}`}
                  className={cn(
                    "flex size-9 items-center justify-center rounded-lg border text-sm font-medium transition-colors focus-visible:ring-3 focus-visible:ring-kps-primary/25 focus-visible:outline-none",
                    selected
                      ? "border-kps-primary bg-kps-primary text-white"
                      : available
                        ? "border-kps-primary/30 bg-kps-primary/10 text-kps-primary hover:border-kps-primary hover:bg-kps-primary/20 dark:border-kps-primary/40 dark:bg-kps-primary/20 dark:text-sidebar-selected"
                        : "cursor-not-allowed border-transparent text-gray-300 opacity-60 dark:text-gray-600",
                  )}
                  onClick={() => selectDate(currentDate)}
                  onKeyDown={(event) => {
                    if (["ArrowLeft", "ArrowUp"].includes(event.key)) {
                      event.preventDefault();
                      moveFocus(currentDate, -1);
                    } else if (
                      ["ArrowRight", "ArrowDown"].includes(event.key)
                    ) {
                      event.preventDefault();
                      moveFocus(currentDate, 1);
                    } else if (
                      event.key === "Home" &&
                      availableInVisibleMonth[0]
                    ) {
                      event.preventDefault();
                      focusDateButton(availableInVisibleMonth[0]);
                    } else if (
                      event.key === "End" &&
                      availableInVisibleMonth.at(-1)
                    ) {
                      event.preventDefault();
                      focusDateButton(availableInVisibleMonth.at(-1)!);
                    }
                  }}
                >
                  {day}
                </button>
              );
            })}
          </div>

          <p className="mt-3 text-theme-xs leading-5 text-gray-500 dark:text-gray-400">
            {t("eligibleHint")}
          </p>
        </div>
      )}
    </div>
  );
}
