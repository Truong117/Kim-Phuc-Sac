import ComponentCard from "@/components/common/ComponentCard";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import type { ReportHoliday, ReportHolidayInput } from "@/types/reportHolidays";
import {
  countScheduledReportDays,
  overlappingReportHoliday,
} from "@/utils/reportHolidays";
import { isDateOnly } from "@/utils/dateOnly";
import { useState, type FormEvent } from "react";
import { useTranslation } from "react-i18next";

interface ReportHolidayFormProps {
  holiday: ReportHoliday | null;
  rows: ReportHoliday[];
  businessDate: string;
  workingDays: number[];
  saving: boolean;
  error: string | null;
  onSave: (input: ReportHolidayInput) => void;
  onCancel: () => void;
}

export default function ReportHolidayForm({
  holiday,
  rows,
  businessDate,
  workingDays,
  saving,
  error,
  onSave,
  onCancel,
}: ReportHolidayFormProps) {
  const { t } = useTranslation("common", { keyPrefix: "reportHolidays" });
  const [name, setName] = useState(holiday?.name ?? "");
  const [start, setStart] = useState(holiday?.start_date ?? businessDate);
  const [end, setEnd] = useState(holiday?.end_date ?? businessDate);
  const [note, setNote] = useState(holiday?.note ?? "");
  const [range, setRange] = useState(
    Boolean(holiday && holiday.start_date !== holiday.end_date),
  );
  const [validation, setValidation] = useState<string | null>(null);
  const endDate = range ? end : start;
  const count = countScheduledReportDays(start, endDate, workingDays);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const overlap = overlappingReportHoliday(rows, start, endDate, holiday?.id);
    const message = !name.trim()
      ? t("errors.name")
      : !isDateOnly(start) || !isDateOnly(endDate)
        ? t("errors.dates")
        : endDate < start
          ? t("errors.order")
          : !count
            ? t("errors.noWorkingDays")
            : (holiday?.is_active ?? true) && overlap
              ? t("errors.overlap", { name: overlap.name })
              : null;
    setValidation(message);
    if (!message)
      onSave({
        name: name.trim(),
        start_date: start,
        end_date: endDate,
        note: note.trim() || null,
      });
  };

  return (
    <ComponentCard
      title={t(holiday ? "editTitle" : "addTitle")}
      compact
      className="border-kps-primary/40 dark:border-sidebar-selected/50"
    >
      <form onSubmit={submit} className="space-y-5" aria-busy={saving}>
        <div>
          <Label htmlFor="holiday-name">{t("name")} *</Label>
          <Input
            id="holiday-name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder={t("namePlaceholder")}
            maxLength={100}
            required
            disabled={saving}
          />
        </div>
        <div
          className="inline-flex gap-1 rounded-lg border border-gray-200 bg-gray-50 p-1 dark:border-gray-700 dark:bg-gray-900"
          role="group"
          aria-label={t("selectionMode")}
        >
          {[false, true].map((value) => (
            <button
              type="button"
              key={String(value)}
              aria-pressed={range === value}
              disabled={saving}
              onClick={() => {
                setRange(value);
                if (value && end < start) setEnd(start);
                setValidation(null);
              }}
              className={`rounded-md px-4 py-2 text-theme-sm font-medium focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none disabled:opacity-50 ${range === value ? "bg-white text-kps-primary shadow-theme-xs dark:bg-gray-800 dark:text-sidebar-selected" : "text-gray-500 dark:text-gray-400"}`}
            >
              {t(value ? "range" : "single")}
            </button>
          ))}
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="holiday-start">
              {t(range ? "fromDate" : "date")} *
            </Label>
            <KpsDatePicker
              id="holiday-start"
              value={start}
              onChange={(value) => {
                setStart(value);
                setValidation(null);
              }}
              ariaLabel={t(range ? "fromDate" : "date")}
              placeholder={t("datePlaceholder")}
              required
              disabled={saving}
            />
          </div>
          {range && (
            <div>
              <Label htmlFor="holiday-end">{t("toDate")} *</Label>
              <KpsDatePicker
                id="holiday-end"
                value={end}
                onChange={(value) => {
                  setEnd(value);
                  setValidation(null);
                }}
                ariaLabel={t("toDate")}
                placeholder={t("datePlaceholder")}
                required
                disabled={saving}
                minDate={start}
              />
            </div>
          )}
        </div>
        <p
          className="rounded-lg bg-kps-primary/5 px-4 py-3 text-theme-sm text-kps-primary dark:bg-kps-primary/15 dark:text-sidebar-selected"
          aria-live="polite"
        >
          {count ? t("impact", { count }) : t("errors.noWorkingDays")}
        </p>
        {start < businessDate && (
          <p className="text-theme-sm text-warning-700 dark:text-warning-400">
            {t("pastImpact")}
          </p>
        )}
        <div>
          <Label htmlFor="holiday-note">{t("note")}</Label>
          <TextArea
            id="holiday-note"
            value={note}
            onChange={setNote}
            rows={2}
            placeholder={t("notePlaceholder")}
            disabled={saving}
            maxLength={240}
          />
        </div>
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("scope")}
        </p>
        {(validation || error) && (
          <p
            role="alert"
            className="text-theme-sm text-error-600 dark:text-error-400"
          >
            {validation ?? error}
          </p>
        )}
        <div className="flex justify-end gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={saving}
            onClick={onCancel}
          >
            {t("cancel")}
          </Button>
          <Button
            type="submit"
            size="sm"
            disabled={saving}
            className="bg-kps-primary hover:bg-kps-primary-hover disabled:bg-kps-primary/60"
          >
            {t(saving ? "saving" : "save")}
          </Button>
        </div>
      </form>
    </ComponentCard>
  );
}
