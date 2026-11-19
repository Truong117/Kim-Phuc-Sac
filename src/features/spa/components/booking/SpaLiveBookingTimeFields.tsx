import { useId } from "react";
import { useTranslation } from "react-i18next";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import { spaFieldClasses } from "../../config";

export default function SpaLiveBookingTimeFields({
  date,
  time,
  onDate,
  onTime,
  invalid = [],
}: {
  date: string;
  time: string;
  onDate: (value: string) => void;
  onTime: (value: string) => void;
  invalid?: string[];
}) {
  const { t } = useTranslation();
  const id = useId();
  const invalidTime = invalid.includes("start_time");
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <div>
        <Label htmlFor={`${id}-date`}>{t("spa.flow.date")}</Label>
        <KpsDatePicker
          id={`${id}-date`}
          value={date}
          onChange={onDate}
          ariaLabel={t("spa.flow.date")}
          placeholder={t("spa.flow.date")}
          error={invalid.includes("date")}
          required
        />
      </div>
      <div>
        <Label htmlFor={`${id}-time`}>{t("spa.flow.time")}</Label>
        <Input
          id={`${id}-time`}
          type="time"
          value={time}
          onChange={(event) => onTime(event.target.value)}
          className={spaFieldClasses}
          aria-invalid={invalidTime || undefined}
          error={invalidTime}
          required
        />
      </div>
    </div>
  );
}
