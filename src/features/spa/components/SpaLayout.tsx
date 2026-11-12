import Select from "@/components/form/Select";
import Badge from "@/components/ui/badge/Badge";
import { useTranslation } from "react-i18next";
import { NavLink, Outlet, useLocation, useNavigate } from "react-router";
import { spaNavigation } from "../config";

export default function SpaLayout() {
  const { t } = useTranslation();
  const { pathname } = useLocation();
  const navigate = useNavigate();
  return (
    <div className="min-w-0 space-y-6">
      <section
        className="rounded-2xl border border-kps-primary/20 bg-kps-primary/5 p-4 dark:border-kps-primary/50 dark:bg-kps-primary/10"
        aria-label={t("spa.preview.title")}
      >
        <div className="flex flex-wrap items-center gap-2">
          <Badge color="light">{t("spa.preview.badge")}</Badge>
          <h1 className="font-semibold text-gray-900 dark:text-white">
            {t("spa.preview.title")}
          </h1>
        </div>
        <p className="mt-2 text-sm text-gray-600 dark:text-gray-300">
          {t("spa.preview.description")}
        </p>
      </section>
      <nav aria-label={t("spa.navigationLabel")}>
        <div className="md:hidden">
          <label htmlFor="spa-module" className="sr-only">
            {t("spa.navigationLabel")}
          </label>
          <Select
            id="spa-module"
            placeholder={t("spa.navigationLabel")}
            value={pathname}
            onChange={navigate}
            options={spaNavigation.map((item) => ({
              value: item.path,
              label: t(`spa.navigation.${item.key}`),
            }))}
          />
        </div>
        <div className="hidden flex-wrap gap-1 rounded-2xl border border-gray-200 bg-white p-2 md:flex dark:border-gray-800 dark:bg-gray-900">
          {spaNavigation.map((item) => (
            <NavLink
              key={item.key}
              to={item.path}
              end
              className={({ isActive }) =>
                `inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:ring-2 focus-visible:ring-kps-primary focus-visible:outline-none ${isActive ? "bg-kps-primary/5 text-kps-primary-hover dark:bg-kps-primary/15 dark:text-gray-200" : "text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5"}`
              }
            >
              {t(`spa.navigation.${item.key}`)}
            </NavLink>
          ))}
        </div>
      </nav>
      <Outlet />
    </div>
  );
}
