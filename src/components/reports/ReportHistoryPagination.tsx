import { cn } from "@/utils";
import { useTranslation } from "react-i18next";

interface ReportHistoryPaginationProps {
  currentPage: number;
  totalPages: number;
  totalItems: number;
  pageSize: number;
  onPageChange: (page: number) => void;
}

export default function ReportHistoryPagination({
  currentPage,
  totalPages,
  totalItems,
  pageSize,
  onPageChange,
}: ReportHistoryPaginationProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "reportHistory.pagination",
  });
  const firstItem = (currentPage - 1) * pageSize + 1;
  const lastItem = Math.min(currentPage * pageSize, totalItems);
  const pages = Array.from({ length: totalPages }, (_, index) => index + 1);

  return (
    <div className="flex flex-col items-center justify-between gap-4 rounded-2xl border border-gray-200 bg-white px-5 py-4 sm:flex-row dark:border-gray-800 dark:bg-white/3">
      <p className="text-theme-sm text-gray-500 dark:text-gray-400">
        {t("summary", {
          from: firstItem,
          to: lastItem,
          total: totalItems,
        })}
      </p>

      <nav aria-label={t("ariaLabel")}>
        <ul className="flex items-center gap-2">
          <li>
            <button
              type="button"
              disabled={currentPage === 1}
              onClick={() => onPageChange(currentPage - 1)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-theme-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
            >
              {t("previous")}
            </button>
          </li>

          {pages.map((page) => (
            <li key={page}>
              <button
                type="button"
                aria-current={page === currentPage ? "page" : undefined}
                onClick={() => onPageChange(page)}
                className={cn(
                  "flex size-9 items-center justify-center rounded-lg border text-theme-sm font-medium transition-colors",
                  page === currentPage
                    ? "border-sidebar-accent bg-sidebar-accent text-white"
                    : "border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5",
                )}
              >
                {page}
              </button>
            </li>
          ))}

          <li>
            <button
              type="button"
              disabled={currentPage === totalPages}
              onClick={() => onPageChange(currentPage + 1)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-theme-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
            >
              {t("next")}
            </button>
          </li>
        </ul>
      </nav>
    </div>
  );
}
