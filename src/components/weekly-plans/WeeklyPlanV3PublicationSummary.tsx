import type { WeeklyPlanPublicationSummary } from "@/types/weeklyPlanning";
import { weeklyPlanV3TerminalProgress } from "@/utils/weeklyPlanV3ReviewWorkspace";
import { useTranslation } from "react-i18next";

type Props = {
  summary: WeeklyPlanPublicationSummary | undefined;
};

export default function WeeklyPlanV3PublicationSummary({ summary }: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.reviewWorkspace.v3.summary",
  });
  const progress = weeklyPlanV3TerminalProgress(summary);
  const values = summary ?? {
    total: 0,
    awaiting_assistant: 0,
    staged_for_owner: 0,
    hr_correction: 0,
    published: 0,
    no_work_approved: 0,
    phase: "NOT_STARTED" as const,
  };

  return (
    <section
      aria-labelledby="weekly-plan-v3-publication-title"
      className="rounded-2xl border border-kps-primary/20 bg-white p-5 shadow-theme-xs dark:border-kps-primary/30 dark:bg-white/3"
    >
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h2
            id="weekly-plan-v3-publication-title"
            className="font-semibold text-gray-900 dark:text-white"
          >
            {t("title")}
          </h2>
          <p className="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">
            {t(`phase.${values.phase}`)}
          </p>
        </div>
        <p className="text-theme-sm font-semibold text-kps-primary dark:text-white">
          {t("terminalProgress", {
            terminal: progress.terminal,
            total: progress.total,
            percentage: progress.percentage,
          })}
        </p>
      </div>

      <div
        className="mt-4 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"
        role="progressbar"
        aria-label={t("progressLabel")}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={progress.percentage}
      >
        <div
          className="h-full rounded-full bg-kps-primary transition-[width]"
          style={{ width: `${progress.percentage}%` }}
        />
      </div>

      <dl className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        {(
          [
            ["total", values.total],
            ["awaitingAssistant", values.awaiting_assistant],
            ["stagedForOwner", values.staged_for_owner],
            ["hrCorrection", values.hr_correction],
            ["published", values.published],
            ["noWorkApproved", values.no_work_approved],
          ] as const
        ).map(([label, value]) => (
          <div
            key={label}
            className="rounded-xl bg-gray-50 px-3 py-3 dark:bg-gray-800/60"
          >
            <dt className="text-theme-xs text-gray-500 dark:text-gray-400">
              {t(`metrics.${label}`)}
            </dt>
            <dd className="mt-1 text-xl font-bold text-gray-900 dark:text-white">
              {value}
            </dd>
          </div>
        ))}
      </dl>

      {values.total === 0 && (
        <p className="mt-4 rounded-xl bg-gray-50 px-4 py-3 text-theme-sm text-gray-600 dark:bg-gray-800/60 dark:text-gray-400">
          {t("empty")}
        </p>
      )}
    </section>
  );
}
