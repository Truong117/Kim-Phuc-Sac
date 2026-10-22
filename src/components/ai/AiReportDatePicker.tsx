import KpsDatePicker from "@/components/common/KpsDatePicker";
import { useTranslation } from "react-i18next";

interface AiReportDatePickerProps {
  value: string;
  availableDates: string[];
  onChange: (date: string) => void;
  ariaLabel: string;
  placeholder: string;
  disabled?: boolean;
}

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

  return (
    <KpsDatePicker
      value={value}
      allowedDates={availableDates}
      onChange={onChange}
      ariaLabel={ariaLabel}
      placeholder={placeholder}
      disabled={disabled}
      dialogLabel={t("dialogLabel")}
      selectableDateLabel={t("available")}
      disabledDateLabel={t("unavailable")}
      calendarHint={t("eligibleHint")}
      highlightSelectableDates
      className="mt-2"
    />
  );
}
