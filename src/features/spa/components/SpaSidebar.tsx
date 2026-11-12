import { useEffect } from "react";
import { Link, NavLink, useLocation } from "react-router";
import { useTranslation } from "react-i18next";
import BrandMark from "@/components/common/BrandMark";
import { useSidebar } from "@/context/SidebarContext";
import { useAuthorization } from "@/hooks/useAuthorization";
import {
  BoxCubeIcon,
  CalenderIcon,
  CartIcon,
  ChatIcon,
  CloseIcon,
  DocsIcon,
  GridIcon,
  MultiUserIcon,
  SettingsAltIcon,
  TaskIcon,
  UserCircleIcon,
} from "@/icons";
import { canOpenWorkspacePath } from "@/utils/workspace";
import { cn } from "@/utils";
import { spaNavigation } from "../config";

const icons = {
  overview: GridIcon,
  appointments: CalenderIcon,
  reception: MultiUserIcon,
  customers: UserCircleIcon,
  services: BoxCubeIcon,
  treatments: DocsIcon,
  cashier: CartIcon,
  technician: TaskIcon,
  rooms: GridIcon,
  inventory: BoxCubeIcon,
  care: ChatIcon,
  staff: MultiUserIcon,
  finance: CartIcon,
  reports: DocsIcon,
  settings: SettingsAltIcon,
};
const groups = [
  {
    key: "operations",
    sections: ["overview", "appointments", "reception", "technician"],
  },
  {
    key: "customers",
    sections: ["customers", "services", "treatments", "care"],
  },
  {
    key: "management",
    sections: ["cashier", "rooms", "inventory", "staff", "finance", "reports"],
  },
  { key: "system", sections: ["settings"] },
];

export default function SpaSidebar() {
  const { t } = useTranslation();
  const { pathname } = useLocation();
  const { workspaces } = useAuthorization();
  const access = workspaces.find((item) => item.id === "spa");
  const { isExpanded, isHovered, isMobileOpen, setIsHovered, setIsMobileOpen } =
    useSidebar();
  const showContent = isExpanded || isHovered || isMobileOpen;
  useEffect(() => {
    setIsMobileOpen(false);
  }, [pathname, setIsMobileOpen]);
  useEffect(() => {
    if (!isMobileOpen) return;
    const close = (event: KeyboardEvent) => {
      if (event.key === "Escape") setIsMobileOpen(false);
    };
    document.addEventListener("keydown", close);
    return () => document.removeEventListener("keydown", close);
  }, [isMobileOpen, setIsMobileOpen]);
  return (
    <aside
      aria-label={t("workspace.spa")}
      className={cn(
        "fixed inset-s-0 top-0 z-999999 flex h-dvh flex-col border-e border-gray-200 bg-white px-5 text-gray-900 transition-all duration-300 xl:visible xl:translate-x-0 xl:rtl:translate-x-0 dark:border-gray-800 dark:bg-gray-900",
        showContent ? "w-72.5" : "w-22.5",
        isMobileOpen
          ? "visible translate-x-0"
          : "invisible -translate-x-full rtl:translate-x-full",
      )}
      onMouseEnter={() => !isExpanded && setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div className="-mx-5 flex items-center justify-between gap-2 bg-sidebar-accent px-5 py-7">
        <Link to={access?.home ?? "/spa"} aria-label={t("workspace.spa")}>
          <BrandMark
            compact={!showContent}
            inverted
            subtitle={t("workspace.spa")}
          />
        </Link>
        {isMobileOpen && (
          <button
            type="button"
            onClick={() => setIsMobileOpen(false)}
            aria-label={t("header.closeSidebar")}
            className="flex size-11 shrink-0 items-center justify-center rounded-lg text-white hover:bg-white/10 xl:hidden"
          >
            <CloseIcon className="size-5" />
          </button>
        )}
      </div>
      <nav
        aria-label={t("spa.navigationLabel")}
        className="custom-scrollbar min-h-0 flex-1 space-y-6 overflow-y-auto pt-6 pb-8"
      >
        {groups.map((group) => {
          const items = spaNavigation.filter(
            (item) =>
              group.sections.includes(item.key) &&
              canOpenWorkspacePath(workspaces, item.path),
          );
          if (!items.length) return null;
          return (
            <div key={group.key}>
              {showContent && (
                <h2 className="mb-3 text-theme-xs font-semibold tracking-wide text-gray-400 uppercase">
                  {t(`workspace.groups.${group.key}`)}
                </h2>
              )}
              <ul className="space-y-1">
                {items.map((item) => {
                  const Icon = icons[item.key];
                  return (
                    <li key={item.key}>
                      <NavLink
                        to={item.path}
                        end
                        title={
                          showContent
                            ? undefined
                            : t(`spa.navigation.${item.key}`)
                        }
                        className={({ isActive }) =>
                          cn(
                            "flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-theme-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-kps-primary focus-visible:outline-none",
                            isActive
                              ? "bg-kps-primary text-white dark:bg-kps-primary"
                              : "text-gray-600 hover:bg-kps-primary/5 hover:text-kps-primary dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-white",
                            !showContent && "justify-center",
                          )
                        }
                      >
                        <Icon className="size-5 shrink-0" />
                        {showContent && (
                          <span>{t(`spa.navigation.${item.key}`)}</span>
                        )}
                      </NavLink>
                    </li>
                  );
                })}
              </ul>
            </div>
          );
        })}
      </nav>
    </aside>
  );
}
