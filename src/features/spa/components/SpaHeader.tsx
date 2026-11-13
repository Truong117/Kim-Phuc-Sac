import { useState } from "react";
import { Link, useLocation, useNavigate } from "react-router";
import { useTranslation } from "react-i18next";
import { useSidebar } from "@/context/SidebarContext";
import { useAuthorization } from "@/hooks/useAuthorization";
import UserDropdown from "@/components/header/UserDropdown";
import { ThemeToggleButton } from "@/components/common/ThemeToggleButton";
import { BellIcon, ChevronDownIcon, GridIcon, SearchIcon } from "@/icons";
import { canOpenWorkspacePath } from "@/utils/workspace";
import { spaNavigation } from "../config";

export default function SpaHeader() {
  const { t } = useTranslation();
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const { workspaces } = useAuthorization();
  const { isMobileOpen, toggleSidebar, toggleMobileSidebar } = useSidebar();
  const [query, setQuery] = useState("");
  const section =
    spaNavigation.find((item) => item.path === pathname)?.key ?? "overview";
  return (
    <header
      className={`sticky top-0 border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900 ${isMobileOpen ? "z-40" : "z-99999"}`}
    >
      <div className="flex min-h-20 flex-wrap items-center justify-between gap-3 px-4 py-3 md:px-6">
        <div className="flex min-w-0 items-center gap-3">
          <button
            type="button"
            aria-label={t("header.toggleSidebar")}
            onClick={() =>
              window.innerWidth >= 1280
                ? toggleSidebar()
                : toggleMobileSidebar()
            }
            className="flex size-11 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5"
          >
            <GridIcon className="size-5" aria-hidden="true" />
          </button>
          <nav
            aria-label={t("spa.concept.breadcrumb")}
            className="hidden items-center gap-3 text-theme-xs text-gray-500 md:flex dark:text-gray-400"
          >
            <Link
              to={workspaces.find((item) => item.id === "spa")?.home ?? "/spa"}
            >
              {t("workspace.spa")}
            </Link>
            <ChevronDownIcon
              className="size-4 -rotate-90 rtl:rotate-90"
              aria-hidden="true"
            />
            <span
              aria-current="page"
              className="font-medium text-gray-800 dark:text-gray-200"
            >
              {t(`spa.navigation.${section}`)}
            </span>
          </nav>
        </div>
        <div className="flex min-w-0 items-center gap-2 md:gap-4">
          {canOpenWorkspacePath(workspaces, "/spa/customers") && (
            <form
              className="hidden lg:block"
              onSubmit={(event) => {
                event.preventDefault();
                navigate(
                  `/spa/customers?q=${encodeURIComponent(query.trim())}`,
                );
              }}
            >
              <div className="relative">
                <SearchIcon
                  className="pointer-events-none absolute start-3 top-3 size-5 text-gray-400"
                  aria-hidden="true"
                />
                <input
                  type="search"
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                  aria-label={t("spa.concept.globalSearch")}
                  placeholder={t("spa.concept.globalSearch")}
                  className="min-h-11 w-60 rounded-lg border border-gray-200 bg-gray-50 ps-10 pe-3 text-base text-gray-800 focus:border-kps-primary focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200"
                />
              </div>
            </form>
          )}
          <ThemeToggleButton />
          {canOpenWorkspacePath(workspaces, "/spa/care") && (
            <Link
              to="/spa/care"
              aria-label={t("spa.concept.attention")}
              className="hidden size-11 items-center justify-center rounded-lg border border-gray-200 text-gray-500 sm:flex dark:border-gray-800 dark:text-gray-400"
            >
              <BellIcon className="size-5" aria-hidden="true" />
            </Link>
          )}
          <UserDropdown compact />
        </div>
      </div>
    </header>
  );
}
