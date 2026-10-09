import Badge from "@/components/ui/badge/Badge";
import { AlertHexaIcon, ArrowRightIcon } from "@/icons";
import type { DashboardAttentionItem } from "@/types/dashboard";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

interface AttentionItemsProps {
  items: DashboardAttentionItem[];
}

export default function AttentionItems({ items }: AttentionItemsProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "overview.attention",
  });

  return (
    <section className="h-full rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs sm:p-6 dark:border-gray-800 dark:bg-white/3">
      <div className="flex items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <span className="flex size-10 items-center justify-center rounded-xl bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500">
            <AlertHexaIcon className="size-5" />
          </span>
          <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
            {t("title")}
          </h2>
        </div>
        {items.length > 0 && (
          <Badge size="sm" color="error">
            {items.length}
          </Badge>
        )}
      </div>

      {items.length === 0 ? (
        <p className="mt-5 rounded-xl bg-gray-50 px-4 py-6 text-center text-theme-sm text-gray-500 dark:bg-white/3 dark:text-gray-400">
          {t("empty")}
        </p>
      ) : (
        <ul className="mt-5 divide-y divide-gray-100 dark:divide-gray-800">
          {items.map((item) => (
            <li key={item.item_id} className="py-4 first:pt-0 last:pb-0">
              <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                  <p className="line-clamp-2 text-theme-sm font-medium text-gray-900 dark:text-white">
                    {item.content}
                  </p>
                  <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    {item.employee.name}
                    {item.department?.name
                      ? ` · ${item.department.name}`
                      : item.location?.name
                        ? ` · ${item.location.name}`
                        : ""}
                  </p>
                </div>
                <Link
                  to={`/reports/${item.report_id}`}
                  aria-label={t("viewItem", { employee: item.employee.name })}
                  className="shrink-0 rounded-lg p-2 text-kps-primary transition-colors hover:bg-kps-primary/10 hover:text-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none dark:text-sidebar-selected"
                >
                  <ArrowRightIcon className="size-5 rtl:rotate-180" />
                </Link>
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
