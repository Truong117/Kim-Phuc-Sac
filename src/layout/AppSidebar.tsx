import BrandMark from "@/components/common/BrandMark";
import { useSidebar } from "@/context/SidebarContext";
import {
  AiIcon,
  BoxCubeIcon,
  CalenderIcon,
  CartIcon,
  ChatIcon,
  DocsIcon,
  GridIcon,
  HorizontaLDots,
  MultiUserIcon,
  PlugInIcon,
  SettingsAltIcon,
  TaskIcon,
  UserCircleIcon,
} from "@/icons";
import { cn } from "@/utils";
import { useEffect } from "react";
import { useTranslation } from "react-i18next";
import { Link, useLocation } from "react-router";

interface NavItem {
  nameKey: string;
  icon: React.ReactNode;
  path: string;
  matchChildren?: boolean;
  excludedChildPaths?: string[];
}

interface NavGroup {
  labelKey?: string;
  items: NavItem[];
}

const navGroups: NavGroup[] = [
  {
    items: [
      {
        nameKey: "dashboard",
        icon: <GridIcon fontSize={24} />,
        path: "/dashboard",
      },
    ],
  },
  {
    labelKey: "work",
    items: [
      {
        nameKey: "newReport",
        icon: <DocsIcon fontSize={24} />,
        path: "/reports/new",
      },
      {
        nameKey: "reportHistory",
        icon: <CalenderIcon fontSize={24} />,
        path: "/reports",
        matchChildren: true,
        excludedChildPaths: ["/reports/new"],
      },
      {
        nameKey: "tasks",
        icon: <TaskIcon fontSize={24} />,
        path: "/tasks",
      },
    ],
  },
  {
    labelKey: "business",
    items: [
      {
        nameKey: "customers",
        icon: <UserCircleIcon fontSize={24} />,
        path: "/customers",
        matchChildren: true,
      },
      {
        nameKey: "products",
        icon: <BoxCubeIcon fontSize={24} />,
        path: "/products",
      },
      {
        nameKey: "orders",
        icon: <CartIcon fontSize={24} />,
        path: "/orders",
      },
    ],
  },
  {
    labelKey: "aiCenter",
    items: [
      {
        nameKey: "aiInsights",
        icon: <AiIcon fontSize={24} />,
        path: "/ai/insights",
      },
      {
        nameKey: "aiAssistant",
        icon: <ChatIcon fontSize={24} />,
        path: "/ai/assistant",
      },
    ],
  },
  {
    labelKey: "system",
    items: [
      {
        nameKey: "employees",
        icon: <MultiUserIcon fontSize={24} />,
        path: "/employees",
      },
      {
        nameKey: "integrations",
        icon: <PlugInIcon fontSize={24} />,
        path: "/integrations",
      },
      {
        nameKey: "settings",
        icon: <SettingsAltIcon fontSize={24} />,
        path: "/settings",
      },
    ],
  },
];

export default function AppSidebar() {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered, setIsMobileOpen } =
    useSidebar();
  const { t } = useTranslation();
  const location = useLocation();
  const showContent = isExpanded || isHovered || isMobileOpen;

  useEffect(() => {
    if (isMobileOpen) {
      setIsMobileOpen(false);
    }
    // Close the mobile navigation whenever the current route changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname]);

  const isActive = (item: NavItem) => {
    if (location.pathname === item.path) {
      return true;
    }

    if (!item.matchChildren || !location.pathname.startsWith(`${item.path}/`)) {
      return false;
    }

    return !item.excludedChildPaths?.some(
      (path) =>
        location.pathname === path || location.pathname.startsWith(`${path}/`),
    );
  };

  return (
    <aside
      className={cn(
        "fixed inset-s-0 top-0 z-50 flex h-screen flex-col border-e border-gray-200 bg-white px-5 text-gray-900 transition-all duration-300 ease-in-out xl:translate-x-0 xl:rtl:translate-x-0 dark:border-gray-800 dark:bg-gray-900",
        isExpanded || isMobileOpen ? "w-72.5" : isHovered ? "w-72.5" : "w-22.5",
        isMobileOpen
          ? "translate-x-0"
          : "-translate-x-full rtl:translate-x-full",
      )}
      onMouseEnter={() => !isExpanded && setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div
        className={cn(
          "-mx-5 flex bg-orange-900 px-5 py-7",
          !isExpanded && !isHovered ? "xl:justify-center" : "justify-start",
        )}
      >
        <Link to="/dashboard" aria-label="KIM PHỤC SẮC Internal System">
          <BrandMark compact={!showContent} inverted />
        </Link>
      </div>

      <div className="no-scrollbar flex flex-col overflow-y-auto pt-5 pb-8 duration-300 ease-linear">
        <nav>
          <div className="flex flex-col gap-5">
            {navGroups.map((group, groupIndex) => (
              <div key={group.labelKey ?? `primary-${groupIndex}`}>
                {group.labelKey && (
                  <h2
                    className={cn(
                      "mb-2.5 flex min-h-5 items-center text-theme-xs font-semibold tracking-wider text-gray-400 uppercase",
                      !isExpanded && !isHovered
                        ? "xl:justify-center"
                        : "justify-start",
                    )}
                  >
                    {showContent ? (
                      t(`sidebar.groups.${group.labelKey}`)
                    ) : (
                      <HorizontaLDots className="size-6" />
                    )}
                  </h2>
                )}

                <ul className="flex flex-col gap-1">
                  {group.items.map((item) => {
                    const active = isActive(item);

                    return (
                      <li key={item.path}>
                        <Link
                          to={item.path}
                          aria-current={active ? "page" : undefined}
                          title={
                            showContent
                              ? undefined
                              : t(`sidebar.items.${item.nameKey}`)
                          }
                          className={cn(
                            "group menu-item",
                            active ? "menu-item-active" : "menu-item-inactive",
                            !showContent && "xl:justify-center",
                          )}
                        >
                          <span
                            className={cn(
                              "menu-item-icon-size",
                              active
                                ? "menu-item-icon-active"
                                : "menu-item-icon-inactive",
                            )}
                          >
                            {item.icon}
                          </span>
                          {showContent && (
                            <span className="menu-item-text">
                              {t(`sidebar.items.${item.nameKey}`)}
                            </span>
                          )}
                        </Link>
                      </li>
                    );
                  })}
                </ul>
              </div>
            ))}
          </div>
        </nav>
      </div>
    </aside>
  );
}
