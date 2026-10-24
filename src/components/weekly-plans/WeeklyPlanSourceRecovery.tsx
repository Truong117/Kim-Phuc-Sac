import Button from "@/components/ui/button/Button";
import type { WeeklyPlan } from "@/types/weeklyPlanning";
import { useState } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  plan: WeeklyPlan;
  busy: boolean;
  onReplace: (file: File) => void;
};

export default function WeeklyPlanSourceRecovery({
  plan,
  busy,
  onReplace,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.sourceRecovery",
  });
  const [replacement, setReplacement] = useState<File | null>(null);
  const availability = plan.source_file.availability;

  if (!availability || availability === "AVAILABLE") return null;

  const extension = plan.source_file.extension.toLowerCase();

  return (
    <div
      role="alert"
      className="rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-800 dark:bg-warning-500/10"
    >
      <p className="text-theme-sm font-semibold text-warning-800 dark:text-warning-300">
        {availability === "MISSING" ? t("missingTitle") : t("unavailableTitle")}
      </p>
      <p className="mt-1 text-theme-sm text-warning-700 dark:text-warning-400">
        {availability === "MISSING"
          ? t("missingHelp", { extension })
          : t("unavailableHelp")}
      </p>

      {availability === "MISSING" && plan.actions.can_replace_source && (
        <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center">
          <input
            type="file"
            accept={`.${extension}`}
            aria-label={t("fileLabel", { extension })}
            onChange={(event) =>
              setReplacement(event.target.files?.[0] ?? null)
            }
            className="block min-w-0 flex-1 rounded-lg border border-gray-300 bg-white p-2.5 text-theme-sm text-gray-700 file:me-3 file:rounded-lg file:border-0 file:bg-kps-primary/10 file:px-3 file:py-2 file:font-semibold file:text-kps-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
          />
          <Button
            type="button"
            disabled={busy || !replacement}
            className="bg-kps-primary hover:bg-kps-primary-hover"
            onClick={() => {
              if (replacement) onReplace(replacement);
            }}
          >
            {busy ? t("uploading") : t("replace")}
          </Button>
        </div>
      )}
    </div>
  );
}
