import { useAuthorization } from "@/hooks/useAuthorization";
import { cn } from "@/utils";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

type WorkManagementTab = "tasks" | "weekly-plans";

interface WorkManagementTabsProps {
  activeTab: WorkManagementTab;
}

export default function WorkManagementTabs({
  activeTab,
}: WorkManagementTabsProps) {
  const { t } = useTranslation("common", { keyPrefix: "taskManagement.tabs" });
  const { canNavigate } = useAuthorization();
  const tabs = [
    canNavigate("tasks")
      ? { id: "tasks" as const, label: t("tasks"), to: "/tasks" }
      : null,
    canNavigate("weekly-plans")
      ? {
          id: "weekly-plans" as const,
          label: t("weeklyPlans"),
          to: "/weekly-plans",
        }
      : null,
  ].filter((tab): tab is NonNullable<typeof tab> => tab !== null);

  if (tabs.length < 2) {
    return null;
  }

  return (
    <nav
      aria-label={t("ariaLabel")}
      className="mb-6 border-b border-gray-200 dark:border-gray-800"
    >
      <div className="flex gap-6" role="tablist">
        {tabs.map((tab) => {
          const isActive = activeTab === tab.id;

          return (
            <Link
              key={tab.id}
              to={tab.to}
              role="tab"
              aria-selected={isActive}
              aria-current={isActive ? "page" : undefined}
              className={cn(
                "border-b-2 px-1 pb-3 text-theme-sm font-semibold transition-colors focus-visible:ring-3 focus-visible:ring-kps-primary/25 focus-visible:outline-none",
                isActive
                  ? "border-kps-primary text-kps-primary dark:text-sidebar-selected"
                  : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-800 dark:text-gray-400 dark:hover:border-gray-700 dark:hover:text-gray-200",
              )}
            >
              {tab.label}
            </Link>
          );
        })}
      </div>
    </nav>
  );
}
