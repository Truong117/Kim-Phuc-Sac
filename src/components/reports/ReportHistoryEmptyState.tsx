import Button from "@/components/ui/button/Button";
import { SearchIcon } from "@/icons";
import { useTranslation } from "react-i18next";

interface ReportHistoryEmptyStateProps {
  onReset: () => void;
}

export default function ReportHistoryEmptyState({
  onReset,
}: ReportHistoryEmptyStateProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "reportHistory.empty",
  });

  return (
    <section className="rounded-2xl border border-gray-200 bg-white px-5 py-14 text-center shadow-theme-xs dark:border-gray-800 dark:bg-white/3">
      <span className="mx-auto flex size-14 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400">
        <SearchIcon className="size-7" />
      </span>
      <h2 className="mt-5 text-lg font-semibold text-gray-900 dark:text-white">
        {t("title")}
      </h2>
      <p className="mx-auto mt-2 max-w-md text-theme-sm text-gray-500 dark:text-gray-400">
        {t("description")}
      </p>
      <Button
        type="button"
        size="sm"
        className="mt-6 !bg-sidebar-accent hover:opacity-90"
        onClick={onReset}
      >
        {t("reset")}
      </Button>
    </section>
  );
}
