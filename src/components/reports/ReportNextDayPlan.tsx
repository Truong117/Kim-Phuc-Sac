import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import { useTranslation } from "react-i18next";
export default function ReportNextDayPlan({
  value,
  onChange,
  disabled,
  readOnly = false,
}: {
  value: string;
  onChange?: (value: string) => void;
  disabled?: boolean;
  readOnly?: boolean;
}) {
  const { t } = useTranslation("common", { keyPrefix: "dailyReport.v3" });
  return (
    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
      <h2 className="font-semibold text-gray-900 dark:text-white">
        {t("planTitle")}
      </h2>
      {readOnly ? (
        <p className="mt-3 text-theme-sm break-words whitespace-pre-wrap text-gray-700 dark:text-gray-300">
          {value || t("planMissing")}
        </p>
      ) : (
        <div className="mt-3">
          <Label htmlFor="next-day-plan">{t("planLabel")}</Label>
          <TextArea
            id="next-day-plan"
            value={value}
            rows={4}
            disabled={disabled}
            placeholder={t("planPlaceholder")}
            onChange={(text) => onChange?.(text)}
            error={value.length > 5000}
            hint={value.length > 5000 ? t("planTooLong") : undefined}
          />
        </div>
      )}
      <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
        {t("planHint")}
      </p>
    </section>
  );
}
