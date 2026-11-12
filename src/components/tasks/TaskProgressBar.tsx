import { useTranslation } from "react-i18next";
import type { TaskProgress } from "@/types/tasks";

export default function TaskProgressBar({
  progress,
  pending = false,
}: {
  progress?: TaskProgress | null;
  pending?: boolean;
}) {
  const { t } = useTranslation("common", { keyPrefix: "taskProgress" });
  if (!progress) return null;
  return (
    <div className="mt-3 space-y-2 text-theme-xs text-gray-600 dark:text-gray-300">
      <div className="flex flex-wrap justify-between gap-2">
        <span>
          {progress.target && progress.unit
            ? t("quantity", {
                done: progress.completed,
                target: progress.target,
                unit: progress.unit,
              })
            : progress.unit
              ? t("recorded", { done: progress.completed, unit: progress.unit })
              : t("noTarget")}
        </span>
        {progress.percent !== null && <span>{progress.percent}%</span>}
      </div>
      {progress.percent !== null && (
        <div
          role="progressbar"
          aria-label={t("progressLabel")}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={progress.percent}
          className="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"
        >
          <div
            className="h-full rounded-full bg-kps-primary"
            style={{ width: `${progress.percent}%` }}
          />
        </div>
      )}
      <p className="text-gray-500 dark:text-gray-400">
        {t(pending ? "pending" : "selfReported")}
      </p>
    </div>
  );
}
