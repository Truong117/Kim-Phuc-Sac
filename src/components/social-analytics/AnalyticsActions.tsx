import { useState } from "react";
import { useTranslation } from "react-i18next";
import AnalyticsAiPanel from "./AnalyticsAiPanel";
import type { FacebookAnalyticsData } from "@/types/facebookAnalytics";
import { formatDateOnly } from "@/utils/dateOnly";

interface Props {
  data: FacebookAnalyticsData;
  busy: boolean;
  onGenerate: () => void;
  onPost: (id: number) => void;
}
export default function AnalyticsActions(props: Props) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const [mode, setMode] = useState("period");
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2">
          {["period", "month"].map((key) => (
            <button
              key={key}
              type="button"
              aria-pressed={mode === key}
              onClick={() => setMode(key)}
              className={
                "min-h-10 rounded-lg border px-3 py-2 text-xs " +
                (mode === key
                  ? "border-kps-primary bg-kps-primary/10 text-kps-primary dark:text-brand-300"
                  : "border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300")
              }
            >
              {t("review.reviewModes." + key)}
            </button>
          ))}
        </div>
        <p className="text-xs text-gray-500 dark:text-gray-400">
          {formatDateOnly(props.data.from_date)}–
          {formatDateOnly(props.data.to_date)}
        </p>
      </div>
      {mode === "period" ? (
        <>
          <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
            {t("review.aiCurrentScope")}
          </p>
          <AnalyticsAiPanel {...props} />
        </>
      ) : (
        <section className="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
          <span className="inline-block rounded-md bg-warning-50 px-2 py-1 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
            {t("review.nextPhase")}
          </span>
          <h2 className="font-semibold text-gray-800 dark:text-gray-100">
            {t("review.monthTitle")}
          </h2>
          <p
            role="status"
            className="text-sm leading-relaxed text-gray-600 dark:text-gray-300"
          >
            {t("review.monthNotReady")}
          </p>
          <div className="grid gap-4 sm:grid-cols-2">
            {["activity", "cohort"].map((key) => (
              <div
                key={key}
                className="space-y-2 border-s-2 border-kps-primary/40 ps-3"
              >
                <h3 className="text-sm font-medium text-gray-800 dark:text-gray-100">
                  {t("review.monthSections." + key + ".title")}
                </h3>
                <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                  {t("review.monthSections." + key + ".body")}
                </p>
              </div>
            ))}
          </div>
        </section>
      )}
    </div>
  );
}
