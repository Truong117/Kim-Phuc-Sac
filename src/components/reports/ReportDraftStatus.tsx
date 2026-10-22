import Button from "@/components/ui/button/Button";
import type { DraftIndicatorState } from "@/hooks/useWorkReportDraft";
import { useTranslation } from "react-i18next";

interface ReportDraftStatusProps {
  indicator: DraftIndicatorState;
  canDiscard: boolean;
  disabled: boolean;
  onDiscard: () => void;
}

const formatSavedTime = (value: string) =>
  new Intl.DateTimeFormat("vi-VN", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
    timeZone: "Asia/Ho_Chi_Minh",
  }).format(new Date(value));

export default function ReportDraftStatus({
  indicator,
  canDiscard,
  disabled,
  onDiscard,
}: ReportDraftStatusProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "dailyReport.draft",
  });

  const label = (() => {
    if (indicator.status === "saved") {
      return t("status.saved", { time: formatSavedTime(indicator.savedAt) });
    }
    if (indicator.status === "restored") {
      return t("status.restored");
    }
    return t(`status.${indicator.status}`);
  })();

  const toneClassName =
    indicator.status === "error" || indicator.status === "conflict"
      ? "text-error-600 dark:text-error-400"
      : indicator.status === "saved" || indicator.status === "restored"
        ? "text-success-600 dark:text-success-400"
        : "text-gray-600 dark:text-gray-300";

  return (
    <section className="flex flex-col gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-700 dark:bg-gray-900/50">
      <div className="min-w-0">
        <p
          aria-live="polite"
          className={`text-theme-sm font-medium ${toneClassName}`}
        >
          {label}
        </p>
        <p className="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
          {t("localOnly")}
        </p>
      </div>
      {canDiscard && (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="shrink-0"
          disabled={disabled}
          onClick={onDiscard}
        >
          {t("discard.action")}
        </Button>
      )}
    </section>
  );
}
