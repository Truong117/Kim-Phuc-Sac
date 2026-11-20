import type { ReactNode } from "react";
import { useTranslation } from "react-i18next";
import type { AnalysisEmployee } from "@/types/workReportAnalysis";

export default function WeeklyEmployeeSummary({
  brief,
  children,
}: {
  brief?: AnalysisEmployee["weekly_brief"];
  children: ReactNode;
}) {
  const { t } = useTranslation("common", { keyPrefix: "weeklyEmployeeBrief" });
  if (!brief) return <>{children}</>;

  return (
    <div className="space-y-5">
      {brief.figures.length > 0 && (
        <section>
          <h3 className="mb-2 font-semibold text-gray-900 dark:text-white">
            {t("figures")}
          </h3>
          <dl className="divide-y divide-brand-100 overflow-hidden rounded-lg border border-brand-100 dark:divide-gray-700 dark:border-gray-700">
            {brief.figures.map((figure) => (
              <div
                key={figure.label}
                className="grid gap-1 bg-brand-25 px-4 py-2.5 text-sm sm:grid-cols-[minmax(10rem,1fr)_2fr] sm:gap-4 dark:bg-brand-500/10"
              >
                <dt className="font-medium text-brand-700 dark:text-brand-200">
                  {figure.label}
                </dt>
                <dd className="break-words text-gray-800 dark:text-gray-200">
                  {figure.text}
                </dd>
              </div>
            ))}
          </dl>
        </section>
      )}
      {brief.results.length > 0 && (
        <section>
          <h3 className="mb-2 font-semibold text-gray-900 dark:text-white">
            {t("results")}
          </h3>
          <ul className="list-disc space-y-1.5 ps-5 text-sm leading-6 text-gray-700 marker:text-brand-500 dark:text-gray-300">
            {brief.results.map((line) => (
              <li key={line}>{line}</li>
            ))}
          </ul>
        </section>
      )}
      {brief.follow_up.length > 0 && (
        <section>
          <h3 className="mb-2 font-semibold text-gray-900 dark:text-white">
            {t("followUp")}
          </h3>
          <ul className="space-y-1.5 text-sm leading-6 text-gray-700 dark:text-gray-300">
            {brief.follow_up.map((line) => (
              <li key={line.label + line.text}>
                <span className="font-medium">{line.label}</span>
                {" · "}
                {line.text}
              </li>
            ))}
          </ul>
        </section>
      )}
      <details className="border-t border-gray-200 pt-3 dark:border-gray-700">
        <summary className="cursor-pointer text-sm font-medium text-brand-700 dark:text-brand-200">
          {t("details")}
        </summary>
        <div className="mt-4 space-y-6">{children}</div>
      </details>
    </div>
  );
}
