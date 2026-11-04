import { normalizeNextDayPlan, parseNextDayPlan } from "@/utils/nextDayPlan";
import { useState } from "react";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import { useTranslation } from "react-i18next";
export default function ReportNextDayPlan({
  value,
  onChange,
  disabled,
  readOnly = false,
  referenceDate,
  showValidation = false,
  serverError,
}: {
  value: string;
  onChange?: (value: string) => void;
  disabled?: boolean;
  readOnly?: boolean;
  referenceDate?: string;
  showValidation?: boolean;
  serverError?: string;
}) {
  const { t } = useTranslation("common", { keyPrefix: "dailyReport.v3" });
  const [touched, setTouched] = useState(false);
  const normalized = normalizeNextDayPlan(value);
  const error =
    serverError ??
    (!normalized
      ? t("planRequired")
      : normalized.length > 5000
        ? t("planTooLong")
        : undefined);
  return (
    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
      <h2 className="font-semibold text-gray-900 dark:text-white">
        {t("planTitle")}
      </h2>
      {readOnly ? (
        normalized ? (
          <ul className="mt-3 list-disc space-y-2 ps-5 text-theme-sm break-words text-gray-700 dark:text-gray-300">
            {parseNextDayPlan(value).map((line, index) => (
              <li key={index}>{line}</li>
            ))}
          </ul>
        ) : (
          <p className="mt-3 text-theme-sm text-gray-500 dark:text-gray-400">
            {t("planMissing")}
          </p>
        )
      ) : (
        <div className="mt-3">
          <Label htmlFor="next-day-plan">
            {t("planLabel")} <span className="text-error-500">*</span>
          </Label>
          <TextArea
            id="next-day-plan"
            value={value}
            rows={4}
            required
            onBlur={() => setTouched(true)}
            disabled={disabled}
            placeholder={t("planPlaceholder")}
            onChange={(text) => onChange?.(text)}
            error={Boolean((touched || showValidation) && error)}
            hint={touched || showValidation ? error : undefined}
          />
        </div>
      )}
      <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
        {referenceDate
          ? t("planHistoricalHint", {
              date: referenceDate.split("-").reverse().join("/"),
            })
          : t("planHint")}
      </p>
    </section>
  );
}
