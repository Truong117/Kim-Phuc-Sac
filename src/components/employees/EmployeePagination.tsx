import { cn } from "@/utils";
import { useTranslation } from "react-i18next";

interface EmployeePaginationProps {
  currentPage: number;
  totalPages: number;
  totalItems: number;
  pageSize: number;
  from?: number | null;
  to?: number | null;
  isLoading?: boolean;
  onPageChange: (page: number) => void;
}

const getVisiblePages = (currentPage: number, totalPages: number) => {
  const firstPage = Math.max(1, Math.min(currentPage - 2, totalPages - 4));
  const lastPage = Math.min(totalPages, firstPage + 4);

  return Array.from(
    { length: Math.max(0, lastPage - firstPage + 1) },
    (_, index) => firstPage + index,
  );
};

export default function EmployeePagination({
  currentPage,
  totalPages,
  totalItems,
  pageSize,
  from,
  to,
  isLoading = false,
  onPageChange,
}: EmployeePaginationProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "userManagement.pagination",
  });
  const firstItem = from ?? (currentPage - 1) * pageSize + 1;
  const lastItem = to ?? Math.min(currentPage * pageSize, totalItems);
  const pages = getVisiblePages(currentPage, totalPages);

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
              disabled={isLoading || currentPage <= 1}
              onClick={() => onPageChange(currentPage - 1)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-theme-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
            >
              {t("previous")}
            </button>
          </li>

          {pages.map((page) => (
            <li key={page}>
              <button
                type="button"
                aria-current={page === currentPage ? "page" : undefined}
                disabled={isLoading}
                onClick={() => onPageChange(page)}
                className={cn(
                  "flex size-9 items-center justify-center rounded-lg border text-theme-sm font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40",
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
              disabled={isLoading || currentPage >= totalPages}
              onClick={() => onPageChange(currentPage + 1)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-theme-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
            >
              {t("next")}
            </button>
          </li>
        </ul>
      </nav>
    </div>
  );
}
